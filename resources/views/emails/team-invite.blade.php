<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('mail.team_invite.title') }}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            color: #3C315B;
            background-color: #F6F2FF;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 480px;
            margin: 0 auto;
            padding: 40px 20px;
        }
        .card {
            background: #ffffff;
            border-radius: 24px;
            padding: 32px;
            box-shadow: 0 4px 16px rgba(60, 49, 91, 0.08);
        }
        .logo {
            text-align: center;
            margin-bottom: 24px;
        }
        .logo img {
            height: 48px;
            width: auto;
        }
        h1 {
            font-size: 24px;
            font-weight: 700;
            margin: 0 0 16px 0;
            text-align: center;
        }
        p {
            margin: 0 0 16px 0;
            color: #3C315B;
            opacity: 0.8;
        }
        .cta {
            text-align: center;
            margin: 24px 0;
        }
        .cta a {
            display: inline-block;
            background: linear-gradient(135deg, #7F68C1 0%, #3C315B 100%);
            color: #ffffff;
            text-decoration: none;
            font-weight: 700;
            font-size: 16px;
            border-radius: 16px;
            padding: 16px 32px;
        }
        .fallback {
            font-size: 12px;
            text-align: center;
            word-break: break-all;
            color: #3C315B;
            opacity: 0.48;
        }
        .footer {
            margin-top: 32px;
            padding-top: 24px;
            border-top: 1px solid rgba(60, 49, 91, 0.08);
            text-align: center;
            font-size: 12px;
            color: #3C315B;
            opacity: 0.48;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="logo">
                <img src="{{ asset('images/logo.png') }}" alt="EduAI" style="height: 48px; width: auto;">
            </div>

            <h1>{{ __('mail.team_invite.title') }}</h1>

            <p>{{ __('mail.team_invite.greeting', ['name' => $name]) }}</p>
            <p>{{ __('mail.team_invite.intro', ['role' => $roleLabel]) }}</p>
            <p>{{ __('mail.team_invite.how_to_sign_in') }}</p>

            <div class="cta" style="text-align: center; margin: 24px 0;">
                <a href="{{ $loginUrl }}" style="display: inline-block; background: linear-gradient(135deg, #7F68C1 0%, #3C315B 100%); color: #ffffff; text-decoration: none; font-weight: 700; font-size: 16px; border-radius: 16px; padding: 16px 32px;">{{ __('mail.team_invite.cta') }}</a>
            </div>

            <p class="fallback">{{ $loginUrl }}</p>

            @if ($expiresOn)
                <p>{{ __('mail.team_invite.expires', ['date' => $expiresOn]) }}</p>
            @endif

            <div class="footer">
                <p>{{ __('mail.team_invite.footer_auto') }}</p>
                <p>{{ __('mail.team_invite.footer_no_reply') }}</p>
            </div>
        </div>
    </div>
</body>
</html>
