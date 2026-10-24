@extends('app.layout')
@section('title', 'Payout account')
@section('content')
  <p><a href="/app/statement">‹ Statement</a></p>
  <h1>Payout account</h1>
  <p class="muted">Ride Coop pays what it owes you to this account by bank transfer.</p>
  <form method="post" action="/app/statement/bank">
    @csrf
    <label for="bank_name">Bank</label>
    <input id="bank_name" name="bank_name" value="{{ old('bank_name', $profile?->bank_name) }}" placeholder="e.g. Republic Bank, CIBC FirstCaribbean, credit union">
    @error('bank_name')<div class="error">{{ $message }}</div>@enderror
    <label for="bank_branch">Branch <span class="muted">(optional)</span></label>
    <input id="bank_branch" name="bank_branch" value="{{ old('bank_branch', $profile?->bank_branch) }}">
    <label for="bank_account_name">Name on the account</label>
    <input id="bank_account_name" name="bank_account_name" value="{{ old('bank_account_name', $profile?->bank_account_name) }}">
    @error('bank_account_name')<div class="error">{{ $message }}</div>@enderror
    <label for="bank_account_number">Account number</label>
    <input id="bank_account_number" name="bank_account_number" inputmode="numeric" value="{{ old('bank_account_number', $profile?->bank_account_number) }}">
    @error('bank_account_number')<div class="error">{{ $message }}</div>@enderror
    <button class="btn btn-primary" type="submit">Save</button>
  </form>
@endsection
