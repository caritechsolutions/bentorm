@extends('app.layout')
@section('title', 'Help')
@section('content')
  <h1>How can we help?</h1>
  <form method="get" action="/app/help"><input type="search" name="q" value="{{ $q }}" placeholder="Search help"></form>
  <div class="card">
    <a class="row" href="/app/support/new"><span><strong>Contact support</strong><br><span class="muted small">Problems with a ride, payment or order</span></span><span class="chev"></span></a>
  </div>
  <div class="card">
    <a class="row" href="/app/support/new?category=lost_item"><span><strong>I lost something</strong><br><span class="muted small">We'll contact the driver for you</span></span><span class="chev"></span></a>
  </div>
  <div class="card">
    <a class="row" href="/app/support/tickets"><span><strong>My messages</strong>@if($open)<br><span class="muted small">{{ $open }} open</span>@endif</span><span class="chev"></span></a>
  </div>
  @forelse ($articles as $category => $list)
    <h2>{{ $category }}</h2>
    <div class="card">
      @foreach ($list as $a)
        <a class="row" href="/app/help/{{ $a->slug }}" style="padding:8px 0;{{ $loop->last ? '' : 'border-bottom:1px solid var(--line)' }}"><span>{{ $a->title }}</span><span class="chev"></span></a>
      @endforeach
    </div>
  @empty
    <p class="muted">{{ $q !== '' ? 'Nothing found for "'.$q.'". Try other words, or contact support.' : 'Help articles are coming soon.' }}</p>
  @endforelse
@endsection
