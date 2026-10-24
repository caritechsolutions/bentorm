@extends('app.layout')
@section('title', 'My messages')
@section('content')
  <p><a href="/app/help">‹ Help</a></p>
  <h1>My messages</h1>
  @forelse ($tickets as $t)
    <div class="card">
      <a class="row" href="/app/support/tickets/{{ $t->id }}">
        <span><strong>{{ $t->subject }}</strong><br><span class="muted small">{{ $t->number() }} · {{ $t->last_message_at?->diffForHumans() }}</span></span>
        <span class="pill {{ $t->status === 'closed' ? 'closed' : 'open' }}">{{ ['open' => 'Waiting for us', 'answered' => 'Replied', 'closed' => 'Closed'][$t->status] ?? $t->status }}</span>
      </a>
    </div>
  @empty
    <p class="muted">No messages yet.</p>
  @endforelse
  <a class="btn btn-ghost" href="/app/support/new">New message</a>
@endsection
