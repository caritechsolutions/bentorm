<x-filament-panels::page>
    @php $b = $this->balance(); $store = \Filament\Facades\Filament::getTenant(); $cell = 'padding:8px 10px;border-bottom:1px solid rgba(127,127,127,.18)'; @endphp
    <x-filament::section>
        <div style="font-size:14px;opacity:.7">{{ $b >= 0 ? 'Ride Coop owes you' : 'You owe Ride Coop' }}</div>
        <div style="font-size:32px;font-weight:700">BBD {{ number_format(abs($b), 2) }}</div>
        <div style="font-size:14px;opacity:.7">Your share of delivered orders (items + VAT − commission), less payouts.
            {{ $store->bank_account_number ? 'Paid to '.$store->bank_name.' ••••'.substr(preg_replace('/\D/', '', $store->bank_account_number), -4).'.' : 'Add your bank account in Store settings → Payouts.' }}</div>
    </x-filament::section>
    <x-filament::section>
        <x-slot name="heading">Activity</x-slot>
        @php $entries = $this->entries(); @endphp
        @if ($entries->isEmpty())
            <p style="opacity:.7">Delivered orders show here.</p>
        @else
            <table style="width:100%;border-collapse:collapse;font-size:14px">
                @foreach ($entries as $e)
                    <tr>
                        <td style="{{ $cell }}">{{ $e->created_at?->format('j M Y, g:i a') }}</td>
                        <td style="{{ $cell }}">{{ $e->description }}</td>
                        <td style="{{ $cell }};text-align:right;font-weight:600;color:{{ $e->amount >= 0 ? '#15803d' : '#dc2626' }}">{{ $e->amount >= 0 ? '+' : '−' }}{{ number_format(abs($e->amount), 2) }}</td>
                    </tr>
                @endforeach
            </table>
        @endif
    </x-filament::section>
</x-filament-panels::page>
