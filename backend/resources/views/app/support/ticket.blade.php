@extends('app.layout')
@section('title', $ticket->subject)
@section('content')
  <p><a href="/app/support/tickets">‹ My messages</a></p>
  <h1>{{ $ticket->subject }}</h1>
  <p class="muted small">{{ $ticket->number() }} · {{ $ticket->categoryLabel() }}</p>
  @foreach ($ticket->messages as $m)
    <div class="msg {{ $m->is_staff ? 'them' : 'me' }}">
      @if ($m->is_staff)<strong class="small">Ride Coop support</strong><br>@endif
      {!! nl2br(e($m->body)) !!}
      <div class="small" style="opacity:.7;margin-top:4px">{{ $m->created_at?->diffForHumans() }}</div>
    </div>
  @endforeach
  @if ($ticket->status !== 'closed')
    <form method="post" action="/app/support/tickets/{{ $ticket->id }}" style="margin-top:16px">
      @csrf
      <textarea name="body" rows="3" placeholder="Write a reply" required></textarea>
      <button class="btn btn-primary" type="submit">Send</button>
    </form>
  @else
    <p class="muted">This conversation is closed. <a href="/app/support/new">Start a new one</a> if you need more help.</p>
  @endif
@endsection
