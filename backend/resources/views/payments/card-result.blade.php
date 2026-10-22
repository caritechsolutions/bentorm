@extends('payments.layout')
@section('title', 'Card')
@section('content')
  @if ($pm->status === 'active')
    <h1 class="ok">Card saved</h1>
    <p>{{ ucfirst($pm->brand ?? 'Card') }} ending {{ $pm->last4 }}. You can return to the app.</p>
  @elseif ($pm->status === 'failed')
    <h1 class="bad">Card not saved</h1>
    <p>{{ $pm->failure_reason ?: 'The card could not be added.' }}</p>
  @else
    <h1>Checking your card</h1>
    <p>This can take a moment. You can return to the app.</p>
  @endif
  <a class="btn primary" href="{{ $deepLink }}">Return to RideCoop</a>
@endsection
