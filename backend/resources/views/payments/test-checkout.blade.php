@extends('payments.layout')
@section('title', 'Test payment')
@section('content')
  <span class="tag">TEST GATEWAY — no real money</span>
  <h1>Pay for your trip</h1>
  <div class="amount">{{ $payment->currency }} {{ number_format($payment->amount, 2) }}</div>
  <form method="post" action="{{ $approveUrl }}"><button class="approve" type="submit">Approve payment</button></form>
  <form method="post" action="{{ $declineUrl }}"><button class="decline" type="submit">Decline</button></form>
@endsection
