@extends('app.layout')
@section('title', $article->title)
@section('content')
  <p><a href="/app/help">‹ Help</a></p>
  <h1>{{ $article->title }}</h1>
  <div class="card article">{!! $article->html() !!}</div>
  <p class="muted small">Still need help? <a href="/app/support/new">Contact support</a></p>
@endsection
