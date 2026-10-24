<?php

namespace App\Http\Controllers\AppPages;

use App\Http\Controllers\Controller;
use App\Models\HelpArticle;
use App\Models\Order;
use App\Models\SupportTicket;
use App\Models\Trip;
use App\Services\SupportService;
use App\Support\Features;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Help & support pages inside the apps (/app/help, /app/support/...). */
class SupportPagesController extends Controller
{
    public function __construct(private readonly SupportService $support) {}

    public function help(Request $request): View
    {
        Features::require('support', 'Help');
        $q = trim((string) $request->query('q', ''));
        $articles = HelpArticle::query()->for(SupportService::audienceOf($request->user()))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('title', 'like', "%{$q}%")->orWhere('body', 'like', "%{$q}%")))
            ->orderBy('category')->orderBy('sort_order')->orderBy('title')->get()->groupBy('category');
        $open = SupportTicket::query()->where('user_id', $request->user()->id)->where('status', '!=', 'closed')->count();

        return view('app.help.index', compact('articles', 'q', 'open'));
    }

    public function article(Request $request, string $slug): View
    {
        $article = HelpArticle::query()->for(SupportService::audienceOf($request->user()))->where('slug', $slug)->firstOrFail();

        return view('app.help.article', compact('article'));
    }

    public function create(Request $request): View
    {
        Features::require('support', 'Support');
        $u = $request->user();
        $recent = Trip::query()->where(fn ($w) => $w->where('rider_id', $u->id)->orWhere('driver_id', $u->id))
            ->whereNotNull('requested_at')->latest('id')->limit(10)->get();
        $orders = Order::query()->where('customer_id', $u->id)->latest('id')->limit(5)->get();

        return view('app.support.new', [
            'categories' => SupportTicket::CATEGORIES, 'recent' => $recent, 'orders' => $orders,
            'category' => $request->query('category'), 'tripId' => $request->query('trip'), 'orderId' => $request->query('order'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'category' => ['required', 'in:'.implode(',', array_keys(SupportTicket::CATEGORIES))],
            'trip' => ['nullable', 'string'],
            'order' => ['nullable', 'string'],
            'body' => ['required', 'string', 'min:5', 'max:5000'],
        ], ['body.required' => 'Tell us what happened.', 'body.min' => 'Tell us a little more, please.']);
        $trip = filled($d['trip'] ?? null) ? Trip::query()->where('public_id', $d['trip'])->first() : null;
        $order = filled($d['order'] ?? null) ? Order::query()->where('public_id', $d['order'])->first() : null;
        $t = $this->support->open($request->user(), $d['category'], $d['body'], $trip, $order);

        return redirect('/app/support/tickets/'.$t->id)->with('flash', 'Thanks — we\'ve got your message. We usually reply within a few hours.');
    }

    public function tickets(Request $request): View
    {
        $tickets = SupportTicket::query()->where('user_id', $request->user()->id)->latest('last_message_at')->limit(50)->get();

        return view('app.support.tickets', compact('tickets'));
    }

    public function ticket(Request $request, SupportTicket $ticket): View
    {
        abort_unless($ticket->user_id === $request->user()->id, 404);

        return view('app.support.ticket', ['ticket' => $ticket->load('messages', 'trip')]);
    }

    public function reply(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $d = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $this->support->reply($ticket, $request->user(), $d['body'], false);

        return redirect('/app/support/tickets/'.$ticket->id);
    }
}
