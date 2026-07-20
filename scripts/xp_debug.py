#!/usr/bin/env python3
"""
xp_debug.py — reconstruct WHERE a user's XP came from.

The API stores no XP ledger: `user_stats.xp_points` is a running total the
Flutter app computes client-side and PUTs back. This script rebuilds the ledger
from the two real sources it CAN see:

  1. Per-block XP   — `progress_data.lessons[*].step_progress[*].earnedXp`
                      (bubble +1, exercise-correct +8, exercise-base +5)
  2. Achievement XP — `achievements[*]` × reward from /gamification/config
                      (trophy_first_course +100 is the usual big one)

...then replays the daily soft cap (first 100 XP/day at 100%, remainder at 20%)
in timestamp order to show an EXPECTED total, and compares it to the stored one.

Note the total is not byte-perfectly reproducible: the app awards block XP and
achievement XP from un-awaited async paths, so their real interleaving (which the
soft cap is sensitive to) races. Expect the replay to land within a few XP of the
stored value; the COMPONENT sums are exact.

Usage:
  # Live (needs the stage/prod admin key — from Railway env ADMIN_API_KEY):
  EDU_ADMIN_API_KEY=xxxx ./xp_debug.py 146
  EDU_ADMIN_API_KEY=xxxx EDU_API_BASE=https://app-api-stage.edu-ai.eu/api ./xp_debug.py 146

  # Offline (no key): dump the JSON from the logged-in admin browser console:
  #   copy(await (await fetch('/api/backend/admin/users/146')).text())
  # save to u146.json, then:
  ./xp_debug.py --file u146.json
"""
import sys
import os
import json
import argparse
import datetime as dt
from urllib import request as urlreq
from urllib.error import HTTPError, URLError

# ── App-side constants (mirror lib/core/gamification/gamification_service.dart) ──
DAILY_SOFT_CAP_THRESHOLD = 100   # first 100 XP/day at full rate
DAILY_SOFT_CAP_RATE = 0.20       # everything past the threshold at 20%

# Fallback achievement rewards if /gamification/config is unreachable
# (mirror lib/models/gamification_config_model.dart)
FALLBACK_REWARDS = {
    "trophy_first_lesson": 25, "trophy_five_lessons": 50, "trophy_first_quiz": 30,
    "trophy_first_course": 100, "trophy_week_streak": 50, "trophy_month_streak": 150,
    "goal_xp_100": 10, "goal_xp_500": 50, "goal_xp_1000": 100,
    "goal_streak_3": 15, "goal_streak_14": 70, "goal_level_3": 50, "goal_lessons_10": 60,
    "challenge_xp_5000": 500, "challenge_courses_3": 200, "challenge_streak_60": 300,
}

DEFAULT_BASE = "https://app-api.edu-ai.eu/api"


def _round_half_up(x):
    # Dart's num.round() is round-half-away-from-zero; Python's round() is banker's.
    import math
    return int(math.floor(x + 0.5))


def apply_daily_cap(raw, daily_so_far):
    """Port of GamificationService.applyDailyCap."""
    if raw <= 0:
        return 0
    if daily_so_far >= DAILY_SOFT_CAP_THRESHOLD:
        return _round_half_up(raw * DAILY_SOFT_CAP_RATE)
    room = DAILY_SOFT_CAP_THRESHOLD - daily_so_far
    if raw <= room:
        return raw
    return room + _round_half_up((raw - room) * DAILY_SOFT_CAP_RATE)


def http_get(url, headers):
    req = urlreq.Request(url, headers=headers)
    with urlreq.urlopen(req, timeout=30) as resp:
        return json.loads(resp.read().decode())


def fetch_user(base, uid, key):
    hdr = {"Accept": "application/json", "X-Admin-Key": key}
    return http_get(f"{base}/admin/users/{uid}", hdr)


def fetch_rewards(base, key):
    try:
        hdr = {"Accept": "application/json"}
        if key:
            hdr["X-Admin-Key"] = key
        cfg = http_get(f"{base}/gamification/config", hdr)
        d = cfg.get("data", cfg)
        out = {}
        for grp in ("trophies", "goals", "challenges"):
            for a in d.get(grp, []) or []:
                out[a["id"]] = a.get("xp_reward", a.get("xpReward", 0))
        return out or dict(FALLBACK_REWARDS)
    except Exception:
        return dict(FALLBACK_REWARDS)


def parse_ts(s):
    if not s:
        return None
    s = s.replace("Z", "+00:00")
    try:
        d = dt.datetime.fromisoformat(s)
    except ValueError:
        return None
    if d.tzinfo is None:
        d = d.replace(tzinfo=dt.timezone.utc)
    return d.astimezone(dt.timezone.utc)


def build_events(user, rewards):
    events = []
    for c in user.get("courses", []) or []:
        pd = c.get("progress_data")
        if not isinstance(pd, dict):
            continue
        for lid, L in (pd.get("lessons") or {}).items():
            sp = L.get("step_progress") or {}
            bts = L.get("block_timestamps") or {}
            for bid, b in sp.items():
                xp = b.get("earnedXp") or 0
                if xp <= 0:
                    continue
                ts = parse_ts((bts.get(bid) or {}).get("confirmed_at"))
                events.append({
                    "ts": ts, "kind": "block", "raw": xp,
                    "ref": f"{c.get('course_id')}/{lid}/{bid}",
                })
    for a in user.get("achievements", []) or []:
        aid = a.get("id")
        events.append({
            "ts": parse_ts(a.get("earned_at")), "kind": "achv",
            "raw": rewards.get(aid, 0), "ref": aid,
            "known": aid in rewards,
        })
    # stable sort; events without a ts sink to the end
    events.sort(key=lambda e: (e["ts"] is None, e["ts"] or dt.datetime.max.replace(tzinfo=dt.timezone.utc)))
    return events


def main():
    ap = argparse.ArgumentParser(description="Reconstruct a user's XP ledger.")
    ap.add_argument("user_id", nargs="?", help="user id (live fetch)")
    ap.add_argument("--file", help="read admin user JSON from file instead of fetching")
    ap.add_argument("--base", default=os.environ.get("EDU_API_BASE", DEFAULT_BASE))
    args = ap.parse_args()

    key = os.environ.get("EDU_ADMIN_API_KEY", "")

    if args.file:
        with open(args.file) as f:
            payload = json.load(f)
        user = payload.get("data", payload)
        rewards = fetch_rewards(args.base, key)
    else:
        if not args.user_id:
            ap.error("give a user_id (live) or --file <dump.json>")
        if not key:
            ap.error("live fetch needs EDU_ADMIN_API_KEY (Railway env ADMIN_API_KEY)")
        try:
            payload = fetch_user(args.base, args.user_id, key)
        except HTTPError as e:
            print(f"HTTP {e.code} fetching user: {e.read().decode()[:200]}", file=sys.stderr)
            sys.exit(1)
        except URLError as e:
            print(f"network error: {e}", file=sys.stderr)
            sys.exit(1)
        user = payload.get("data", payload)
        rewards = fetch_rewards(args.base, key)

    stats = user.get("stats") or {}
    stored_xp = stats.get("xp_points", 0)
    ach_count = stats.get("achievements_count", 0)
    ach_rows = len(user.get("achievements") or [])

    events = build_events(user, rewards)

    # ── Replay the daily soft cap in timestamp order ──
    daily = {}          # date(UTC) -> xp so far that day
    running = 0
    block_raw = achv_raw = 0
    print(f"\n=== XP LEDGER · user {user.get('id')} ({user.get('name')}) ===")
    print(f"{'time (UTC)':<20} {'kind':<6} {'raw':>4} {'eff':>4} {'tot':>5}  ref")
    print("-" * 92)
    for e in events:
        day = e["ts"].date() if e["ts"] else None
        so_far = daily.get(day, 0)
        eff = apply_daily_cap(e["raw"], so_far)
        daily[day] = so_far + eff
        running += eff
        if e["kind"] == "block":
            block_raw += e["raw"]
        else:
            achv_raw += e["raw"]
        tstr = e["ts"].strftime("%Y-%m-%d %H:%M:%S") if e["ts"] else "??? (no ts)"
        flag = "" if e.get("known", True) else "  <-- UNKNOWN ID (reward=0?)"
        print(f"{tstr:<20} {e['kind']:<6} {e['raw']:>4} {eff:>4} {running:>5}  {e['ref']}{flag}")

    total_raw = block_raw + achv_raw
    print("-" * 92)
    print(f"\n  block XP (raw)        : {block_raw:>5}   ({sum(1 for e in events if e['kind']=='block')} blocks)")
    print(f"  achievement XP (raw)  : {achv_raw:>5}   ({sum(1 for e in events if e['kind']=='achv')} achievements)")
    print(f"  ─ total RAW           : {total_raw:>5}")
    print(f"  replayed w/ daily cap : {running:>5}   (expected stored value)")
    print(f"  STORED xp_points      : {stored_xp:>5}")
    diff = stored_xp - running
    if diff != 0:
        print(f"  Δ replay vs stored    : {diff:+}   (async block/achv award race — soft cap is order-sensitive)")

    print("\n  achievements breakdown:")
    for a in user.get("achievements") or []:
        aid = a.get("id")
        print(f"    +{rewards.get(aid,0):<4} {aid:<22} {a.get('earned_at')}")

    if ach_count != ach_rows:
        print(f"\n  ⚠ DRIFT: user_stats.achievements_count = {ach_count}, but {ach_rows} rows in user_achievements")

    print()


if __name__ == "__main__":
    main()
