@extends('app.layout')
@section('title', 'Delete your Ride Coop account')
@section('content')
  <h1>Delete your Ride Coop account</h1>
  <div class="card">
    <p>You can delete your account in the Ride Coop or Ride Coop Driver app (Menu → Account → Delete account), or here.</p>
    <p class="small muted"><b>What is removed:</b> your name, phone number, email, saved places, saved cards, and for drivers your documents and bank details. You are signed out on every phone.</p>
    <p class="small muted"><b>What is kept:</b> past trips and payments, without your details, because we must keep financial records and the other person's trip history. Messages to support stay with the ticket.</p>
    <p class="small muted">You can sign up again later with the same number; it will be a new account.</p>
  </div>

  @foreach ($blockers as $b)
    <div class="card" style="border-color:var(--red)"><b>Not yet:</b> {{ $b }}</div>
  @endforeach

  @if (! $phone)
    <form method="post" action="/account/delete/code" class="card">
      @csrf
      <label for="phone">Your mobile number</label>
      <input id="phone" name="phone" type="tel" inputmode="tel" value="{{ old('phone') }}" placeholder="1 246 …" required>
      @error('phone')<div class="error">{{ $message }}</div>@enderror
      <button class="btn btn-primary" type="submit">Text me a code</button>
    </form>
  @else
    <form method="post" action="/account/delete" class="card">
      @csrf
      <p class="small muted">Number: {{ $phone }} · <a href="/account/delete/restart">use another number</a></p>
      <label for="code">Code from the text message</label>
      <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" required>
      @error('code')<div class="error">{{ $message }}</div>@enderror
      <label style="display:flex;gap:10px;align-items:flex-start;font-weight:400"><input type="checkbox" name="confirm" value="1" style="width:auto;margin:4px 0"> I understand my account will be deleted and this can't be undone.</label>
      @error('confirm')<div class="error">{{ $message }}</div>@enderror
      <button class="btn btn-primary" type="submit" style="background:var(--red)">Delete my account</button>
    </form>
  @endif
@endsection
