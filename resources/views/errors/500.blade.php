@extends('layouts.app')

@section('title', 'Something went wrong on our side')

@section('content')
    @include('errors.partial', [
        'code' => '500',
        'title' => 'Something went wrong on our side',
        'message' => 'This is a fault in the system, not something you did. Nothing was charged and no booking was changed. Please try again, and tell the office if it keeps happening.',
    ])
@endsection