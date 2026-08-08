@php
    $hours = fn($column) => $fieldPolicy ? round($fieldPolicy->{$column} / 3600, 2) : null;
@endphp

@if($creating)
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
            <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">Ticket type</label>
            <input name="ticket_type" required maxlength="100" placeholder="Ví dụ: VIP"
                class="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm text-slate-800 placeholder-slate-400 focus:border-sky-500 focus:ring-2 focus:ring-sky-500/20 focus:outline-none transition-all">
        </div>
        <div>
            <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">Priority</label>
            <select name="priority" required
                class="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm text-slate-800 focus:border-sky-500 focus:ring-2 focus:ring-sky-500/20 focus:outline-none transition-all">
                @foreach(['Urgent', 'High', 'Medium', 'Low'] as $priority)
                    <option value="{{ $priority }}">{{ $priority }}</option>
                @endforeach
            </select>
        </div>
    </div>
@endif

<div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
    @foreach(['total' => 'TTR tổng', 'L1' => 'L1', 'L2' => 'L2', 'L3' => 'L3', 'L4' => 'L4', 'RT' => 'RT'] as $name => $label)
    @php($column = $name === 'total' ? 'total_seconds' : strtolower($name) . '_seconds')
    <div>
        <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1">
            {{ $label }} <span class="text-slate-400 font-normal lowercase">(giờ)</span>
        </label>
        <input type="number" name="{{ $name }}" required min="0" max="1000000" step="0.01" value="{{ $hours($column) }}"
            class="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2 font-mono text-sm font-semibold text-slate-900 focus:border-sky-500 focus:ring-2 focus:ring-sky-500/20 focus:outline-none transition-all">
    </div>
    @endforeach
</div>