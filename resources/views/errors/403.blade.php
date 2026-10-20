@extends('layouts.app')

@section('title', 'Access denied')

@section('content')
    {{-- Uses the shared body so the layout, wording register and reference code
         match every other status page. --}}
    @include('errors.partial', [
        'code' => '403',
        'title' => 'Access denied',
        'message' => 'Your account does not have permission to view this page. If you believe this is a mistake, please contact the municipal office.',
    ])
@endsection