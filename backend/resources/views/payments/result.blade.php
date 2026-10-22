@extends('payments.layout')
@section('title', 'Payment')
@section('content')
  @if ($payment->status === 'captured')
    <h1 class="ok">Payment complete</h1>
    <div class="amount">{{ $payment->currency }} {{ number_format($payment->amount, 2) }}</div>
    <p>Thank you. You can return to the app.</p>
  @elseif ($payment->status === 'failed')
    <h1 class="bad">Payment not completed</h1>
    <p>{{ $payment->failure_reason ?: 'The payment was declined.' }}</p>
  @else
    <h1>Payment processing</h1>
    <p>We're waiting for confirmation from the bank. You can return to the app.</p>
  @endif
  <a class="btn primary" href="{{ $deepLink }}">Return to Ride Coop</a>
@endsection
