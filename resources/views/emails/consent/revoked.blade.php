@extends('emails.consent.layout', ['title' => __('consent.revoked_title')])

@section('body')
    <p>{{ __('consent.revoked_intro') }}</p>
    <p>{{ __('consent.revoked_purge', ['days' => $purgeAfterDays]) }}</p>
@endsection
