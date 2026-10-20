@extends('layouts.app')

@section('title', 'That request is not allowed here')

@section('content')
    @include('errors.partial', [
        'code' => '405',
        'title' => 'That request is not allowed here',
        'message' => 'The link or form you used sent something this page cannot accept. This usually means an older page was left open in another tab.',
    ])
@endsection