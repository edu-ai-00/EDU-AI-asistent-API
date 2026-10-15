@extends('emails.consent.layout', ['title' => __('consent.manage_link_title')])

@section('body')
    <p>{{ __('consent.manage_link_intro') }}</p>

    <a class="cta" href="{{ $manageUrl }}">{{ __('consent.manage_link_cta') }}</a>
    <p class="muted">{{ __('consent.manage_link_note') }}</p>
@endsection
