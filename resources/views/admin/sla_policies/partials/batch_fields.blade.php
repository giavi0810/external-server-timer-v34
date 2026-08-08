<label class="block text-sm font-semibold text-slate-700">
    Ticket type
    <input name="ticket_type" required maxlength="100" value="{{ old('ticket_type') }}"
        class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 font-normal" placeholder="Ví dụ: VIP">
</label>

<div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
    @foreach(['Urgent', 'High', 'Medium', 'Low'] as $priority)
        <section class="rounded-xl border border-slate-200 bg-slate-50/60 p-4">
            <div class="mb-4 flex items-center justify-between">
                <h3 class="font-bold text-slate-900">{{ $priority }}</h3>
                <span
                    class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $priority === 'Urgent' ? 'bg-rose-100 text-rose-700' : ($priority === 'High' ? 'bg-orange-100 text-orange-700' : ($priority === 'Medium' ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700')) }}">Priority</span>
            </div>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                @foreach(['total' => 'TTR tổng', 'L1' => 'L1', 'L2' => 'L2', 'L3' => 'L3', 'L4' => 'L4', 'RT' => 'RT'] as $name => $label)
                    <label class="text-xs font-semibold text-slate-700">
                        {{ $label }} (giờ)
                        <input type="number" name="policies[{{ $priority }}][{{ $name }}]" required min="0" max="1000000"
                            step="0.01" value="{{ old("policies.{$priority}.{$name}") }}"
                            class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-normal">
                    </label>
                @endforeach
            </div>
        </section>
    @endforeach
</div>