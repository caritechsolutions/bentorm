@extends('app.layout')
@section('title', $title)
@section('content')
  <h1>{{ $title }}</h1>
  <p class="muted">{{ $body }}</p>
@endsection
