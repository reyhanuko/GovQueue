@extends('layouts.app')

@section('title', 'Your session has expired')

@section('content')
    @include('errors.partial', [
        'code' => '419',
        'title' => 'Your session has expired',
        'message' => 'For your security you are signed out after a period of inactivity. Nothing you submitted was lost - please sign in and try again.',
    ])
@endsection