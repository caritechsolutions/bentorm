@extends('app.layout')
@section('title', $title.' · Ride Coop')
@section('content')
  <div class="card article">{!! $html !!}</div>
  <p class="small muted"><a href="/terms">Terms of Use</a> · <a href="/privacy">Privacy Policy</a> · <a href="/account/delete">Delete my account</a></p>
@endsection
