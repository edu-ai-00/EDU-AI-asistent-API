@extends('emails.consent.layout', ['title' => __('consent.granted_title')])

@section('body')
    @if (count($children) > 0)
        <p>{{ __('consent.granted_intro') }}</p>

        @foreach ($children as $child)
            <div class="child">
                <div class="name">{{ $child['name'] }}</div>
                <div class="child-code">{{ $child['code'] }}</div>
            </div>
        @endforeach

        <p>{{ __('consent.granted_first_login') }}</p>
    @else
        {{-- No new codes this time: the guardian already has every child
             account this consent provisions. Two paths reach this branch —
             a top-up confirmation (a parent re-confirming after losing their
             original codes) and a repeat consent request for an email that
             already consented (BR-2JW929) — so the copy is deliberately
             worded to be true of both. The useful content here is not a
             (nonexistent) code list, it's the names of the accounts that do
             exist plus the manage link right below, where a code can be
             regenerated per child. --}}
        <p>{{ __('consent.granted_no_new_codes_intro') }}</p>

        @if (count($existingChildren) > 0)
            {{-- Names only, never codes: they are stored as hashes and
                 cannot be re-sent. Naming the accounts is what lets a parent
                 pick the right child on the manage page. --}}
            <p>{{ __('consent.granted_existing_children') }}</p>

            @foreach ($existingChildren as $childName)
                <div class="child">
                    <div class="name">{{ $childName }}</div>
                </div>
            @endforeach
        @endif
    @endif

    <a class="cta" href="{{ $manageUrl }}">{{ __('consent.granted_manage_cta') }}</a>
    <p class="muted">{{ __('consent.granted_manage_note') }}</p>
@endsection
