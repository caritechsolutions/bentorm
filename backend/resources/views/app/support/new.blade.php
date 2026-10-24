@extends('app.layout')
@section('title', 'Contact support')
@section('content')
  <p><a href="/app/help">‹ Help</a></p>
  <h1>Contact support</h1>
  <form method="post" action="/app/support">
    @csrf
    <label for="category">What is it about?</label>
    <select id="category" name="category">
      @foreach ($categories as $key => $label)
        <option value="{{ $key }}" @selected(old('category', $category) === $key)>{{ $label }}</option>
      @endforeach
    </select>
    @if ($recent->isNotEmpty())
      <label for="trip">Which trip? <span class="muted">(optional)</span></label>
      <select id="trip" name="trip">
        <option value="">Not about a trip</option>
        @foreach ($recent as $t)
          <option value="{{ $t->public_id }}" @selected(old('trip', $tripId) === $t->public_id)>
            {{ $t->requested_at?->setTimezone($t->serviceArea?->timezone ?? config('app.timezone'))->format('j M, g:i a') }} · {{ $t->order_id ? 'Delivery' : ($t->dropoff_address ?: 'Ride') }}
          </option>
        @endforeach
      </select>
    @endif
    @if ($orders->isNotEmpty())
      <label for="order">Which order? <span class="muted">(optional)</span></label>
      <select id="order" name="order">
        <option value="">Not about an order</option>
        @foreach ($orders as $o)
          <option value="{{ $o->public_id }}" @selected(old('order', $orderId) === $o->public_id)>Order {{ $o->number() }} · {{ $o->store?->name }}</option>
        @endforeach
      </select>
    @endif
    <label for="body">Tell us what happened</label>
    <textarea id="body" name="body" rows="6" placeholder="For a lost item: what it is, its colour, and where you sat.">{{ old('body') }}</textarea>
    @error('body')<div class="error">{{ $message }}</div>@enderror
    <button class="btn btn-primary" type="submit">Send</button>
  </form>
@endsection
