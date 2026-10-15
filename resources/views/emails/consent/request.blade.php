@extends('emails.consent.layout', ['title' => __('consent.request_title')])

@section('body')
    <p>{{ __('consent.request_intro', ['age' => $ageThreshold]) }}</p>

    <h2>{{ __('consent.request_data_title') }}</h2>
    <p>{{ __('consent.request_data_body') }}</p>

    {{--
        The privacy policy link sits above the CTA, in normal body weight, and
        never below it (BR-F9CR44): consent has to be *informed*, so the full
        description of the processing must be reachable before the parent
        presses "Potvrdit souhlas", not buried in the fine print underneath it.
    --}}
    <p>{{ __('consent.request_privacy_intro') }}
        <a href="{{ $privacyUrl }}">{{ __('consent.request_privacy') }}</a></p>

    <p>{{ __('consent.request_confirm_question') }}</p>
    <a class="cta" href="{{ $confirmUrl }}">{{ __('consent.request_cta') }}</a>

    {{-- Fallback for parents whose client strips the button: type this code on
         the confirm page. Belongs after the CTA — it is the secondary path. --}}
    <p class="muted">{{ __('consent.request_code_label') }}</p>
    <div class="code">{{ $code }}</div>

    <p class="muted">{{ __('consent.request_ttl', ['hours' => $ttlHours]) }}</p>
    <p class="muted">{{ __('consent.request_version', ['version' => $textVersion]) }}</p>
    <p class="muted">{{ __('consent.request_ignore') }}</p>
@endsection
