@extends('app.layout')
@section('title', 'About Ride Coop')
@section('content')
  <h1>Ride Coop</h1>
  <p class="muted">Signed in as {{ $user->name }} · {{ $user->phone }}</p>
  <div class="card">
    <p>Ride Coop is Barbados' cooperative ride and delivery app. Owned locally, built for drivers and riders.</p>
  </div>
@endsection
