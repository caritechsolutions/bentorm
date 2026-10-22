@extends('payments.layout')
@section('title', 'Add a card')
@section('content')
  <span class="tag">TEST GATEWAY — no real cards</span>
  <h1>Add a card</h1>
  <p>Pick a test card.</p>
  <form method="post" action="{{ $action }}" style="text-align:left">
    @foreach ($cards as $number => $label)
      <label style="display:block;padding:10px;border:1px solid #d1d5db;border-radius:10px;margin-bottom:8px;cursor:pointer">
        <input type="radio" name="number" value="{{ $number }}" @checked($loop->first)>
        <strong>•••• {{ substr($number, -4) }}</strong> — {{ $label }}
      </label>
    @endforeach
    <input type="hidden" name="exp_month" value="12">
    <input type="hidden" name="exp_year" value="{{ now()->addYears(3)->year }}">
    <input type="text" name="name" placeholder="Name on card" style="width:100%;box-sizing:border-box;padding:12px;border:1px solid #d1d5db;border-radius:10px;font-size:16px;margin-top:4px">
    <button class="primary" type="submit">Save card</button>
  </form>
@endsection
