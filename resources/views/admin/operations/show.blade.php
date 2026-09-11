@extends('admin.layouts.app')

@section('title', 'جزئیات عملیات')
@section('page_badge', 'عملیات')
@section('breadcrumb') <a href="{{ route('admin.operations.index') }}" class="text-gray-500 text-sm hover:text-gray-700">عملیات</a> <span class="text-gray-300 mx-1">/</span> <span class="text-gray-700 text-sm">جزئیات</span> @endsection

@section('content')
@php
    use App\Enums\CustodyActor;
    use App\Enums\RentalOperationState;
    use App\Enums\RentalOperationType;

    // The same three controls serve every leg; only what they are called
    // changes, because the leg decides who hands what to whom.
    $startLabel = match ($operation->type) {
        RentalOperationType::CustomerDelivery => 'آغاز تحویل و باز کردن سابقه',
        RentalOperationType::CustomerReturn => 'آغاز بازگشت و باز کردن سابقه',
        RentalOperationType::OwnerReturn => 'آغاز بازگرداندن به مالک و باز کردن سابقه',
        default => 'آغاز عملیات و درخواست تحویل',
    };

    $recordTitle = match ($operation->type) {
        RentalOperationType::CustomerDelivery => 'ثبت تحویل دستگاه به مشتری',
        RentalOperationType::CustomerReturn => 'ثبت دریافت دستگاه از مشتری',
        RentalOperationType::OwnerReturn => 'ثبت تحویل دستگاه به مالک',
        default => 'ثبت تحویل گرفتن دستگاه',
    };

    $recordHint = match ($operation->type) {
        RentalOperationType::CustomerDelivery =>
            'فقط زمانی ثبت کنید که دستگاه عملاً به مشتری تحویل داده شده و رسید آن امضا شده است. با این کار اجاره فعال می‌شود.',
        RentalOperationType::CustomerReturn =>
            'فقط زمانی ثبت کنید که دستگاه عملاً از مشتری دریافت شده است. با این کار اجاره در وضعیت بازگشت‌داده‌شده ثبت می‌شود.',
        RentalOperationType::OwnerReturn =>
            'فقط زمانی ثبت کنید که دستگاه عملاً به مالک تحویل داده شده است. وضعیت درخواست اجاره با این کار تغییر نمی‌کند.',
        default =>
            'فقط زمانی ثبت کنید که دستگاه را عملاً تحویل گرفته‌اید. با این کار عملیات تکمیل می‌شود.',
    };

    $notesPlaceholder = in_array($operation->type, [RentalOperationType::CustomerDelivery, RentalOperationType::CustomerReturn], true)
        ? 'وضعیت دستگاه هنگام تحویل (اختیاری)'
        : 'یادداشت (اختیاری)';

    $inspectable = $operation->type->inspectionStage() !== null;

    $badge = match ($operation->state) {
        RentalOperationState::Completed => 'bg-green-50 text-green-700 border-green-200',
        RentalOperationState::InProgress => 'bg-blue-50 text-blue-700 border-blue-200',
        RentalOperationState::Scheduled => 'bg-indigo-50 text-indigo-700 border-indigo-200',
        RentalOperationState::AwaitingDeviceAllocation => 'bg-amber-50 text-amber-700 border-amber-200',
        RentalOperationState::Failed => 'bg-red-50 text-red-700 border-red-200',
        RentalOperationState::NotRequired => 'bg-gray-100 text-gray-600 border-gray-200',
        RentalOperationState::Pending => 'bg-gray-50 text-gray-500 border-gray-200',
    };

    $transfer = $operation->custodyTransfer;
    $canManage = auth()->user()->can('manage_operations');
@endphp

@if (session('success'))
    <div class="bg-green-50 border border-green-200 text-green-700 text-sm rounded-xl px-4 py-3 mb-4">{{ session('success') }}</div>
@endif
@if (session('error'))
    <div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-xl px-4 py-3 mb-4">{{ session('error') }}</div>
@endif

<div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
    <div class="lg:col-span-2 space-y-5">
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <div class="flex items-start justify-between gap-3 mb-5">
                <div>
                    <h2 class="text-lg font-black text-gray-800">{{ $operation->type->label() }}</h2>
                    <p class="text-xs text-gray-500 mt-1">شماره عملیات: <span class="font-mono" dir="ltr">{{ $operation->operation_number }}</span></p>
                </div>
                <span class="text-[11px] border rounded-full px-3 py-1 {{ $badge }}">{{ $operation->state->label() }}</span>
            </div>

            <dl class="text-sm divide-y divide-gray-100">
                <div class="flex justify-between py-2.5">
                    <dt class="text-gray-500">شماره درخواست</dt>
                    <dd class="font-mono text-xs text-gray-700" dir="ltr">{{ $operation->application?->application_number ?? '—' }}</dd>
                </div>
                <div class="flex justify-between py-2.5">
                    <dt class="text-gray-500">مشتری</dt>
                    <dd class="text-gray-700">{{ $operation->application?->user?->full_name ?? '—' }}</dd>
                </div>
                <div class="flex justify-between py-2.5">
                    <dt class="text-gray-500">بازه رزرو</dt>
                    <dd class="text-gray-700 text-xs">
                        @if ($operation->reservation)
                            {{ \App\Support\Rental\Jalali::formatLong($operation->reservation->start_date->toDateString()) }}
                            تا
                            {{ \App\Support\Rental\Jalali::formatLong($operation->reservation->end_date->toDateString()) }}
                        @else — @endif
                    </dd>
                </div>
                <div class="flex justify-between py-2.5">
                    <dt class="text-gray-500">دستگاه</dt>
                    <dd class="text-gray-700 text-xs">
                        @if ($operation->device)
                            <a href="{{ route('admin.devices.show', $operation->device) }}" class="text-brandBlue hover:underline">
                                {{ $operation->device->product?->title_fa ?? '—' }}
                            </a>
                            {{-- Masked serial: the raw value stays on the device screen. --}}
                            <span class="font-mono text-gray-400 mr-2" dir="ltr">{{ $operation->device->maskedSerial() }}</span>
                        @else
                            <span class="text-amber-600">هنوز تخصیص نیافته است</span>
                        @endif
                    </dd>
                </div>
                <div class="flex justify-between py-2.5">
                    <dt class="text-gray-500">نوع مالکیت</dt>
                    <dd class="text-gray-700 text-xs">{{ $operation->device?->ownership->label() ?? '—' }}</dd>
                </div>
                <div class="flex justify-between py-2.5">
                    <dt class="text-gray-500">مالک</dt>
                    <dd class="text-gray-700 text-xs">
                        @if ($operation->owner)
                            <a href="{{ route('admin.owners.show', $operation->owner) }}" class="text-brandBlue hover:underline">{{ $operation->owner->displayName() }}</a>
                        @else
                            گیم‌پک
                        @endif
                    </dd>
                </div>
                <div class="flex justify-between py-2.5">
                    <dt class="text-gray-500">مسئول</dt>
                    <dd class="text-gray-700 text-xs">{{ $operation->assignedTo?->full_name ?? '—' }}</dd>
                </div>
                <div class="flex justify-between py-2.5">
                    <dt class="text-gray-500">زمان‌بندی</dt>
                    <dd class="text-gray-700 text-xs" dir="ltr">{{ $operation->scheduled_at?->format('Y-m-d H:i') ?? '—' }}</dd>
                </div>
                <div class="flex justify-between py-2.5">
                    <dt class="text-gray-500">آغاز</dt>
                    <dd class="text-gray-700 text-xs" dir="ltr">{{ $operation->started_at?->format('Y-m-d H:i') ?? '—' }}</dd>
                </div>
                <div class="flex justify-between py-2.5">
                    <dt class="text-gray-500">پایان</dt>
                    <dd class="text-gray-700 text-xs" dir="ltr">{{ $operation->completed_at?->format('Y-m-d H:i') ?? '—' }}</dd>
                </div>
                @if ($operation->failure_reason)
                    <div class="flex justify-between py-2.5">
                        <dt class="text-gray-500">دلیل ناموفق بودن</dt>
                        <dd class="text-red-600 text-xs">{{ $operation->failure_reason }}</dd>
                    </div>
                @endif
            </dl>
        </div>

        {{-- Custody, shown separately from ownership on purpose: the two are
             different facts and must never look like one. --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-black text-gray-800 mb-4">تحویل فیزیکی</h3>

            @if ($transfer)
                <dl class="text-sm divide-y divide-gray-100">
                    <div class="flex justify-between py-2.5">
                        <dt class="text-gray-500">شناسه تحویل</dt>
                        {{-- Internal operational handle. NOT a receipt number
                             and with no legal effect -- see CustodyTransferState. --}}
                        <dd class="font-mono text-xs text-gray-700" dir="ltr">{{ $transfer->reference_number }}</dd>
                    </div>
                    <div class="flex justify-between py-2.5">
                        <dt class="text-gray-500">مسیر</dt>
                        <dd class="text-gray-700 text-xs">{{ $transfer->transfer_type->label() }}</dd>
                    </div>
                    <div class="flex justify-between py-2.5">
                        <dt class="text-gray-500">وضعیت</dt>
                        <dd class="text-gray-700 text-xs">{{ $transfer->state->label() }}</dd>
                    </div>
                    <div class="flex justify-between py-2.5">
                        <dt class="text-gray-500">درخواست</dt>
                        <dd class="text-gray-700 text-xs" dir="ltr">{{ $transfer->initiated_at?->format('Y-m-d H:i') ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between py-2.5">
                        <dt class="text-gray-500">تحویل گرفته شده</dt>
                        <dd class="text-gray-700 text-xs" dir="ltr">{{ $transfer->transferred_at?->format('Y-m-d H:i') ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between py-2.5">
                        <dt class="text-gray-500">تأیید طرف مقابل</dt>
                        <dd class="text-gray-700 text-xs" dir="ltr">{{ $transfer->acknowledged_at?->format('Y-m-d H:i') ?? '—' }}</dd>
                    </div>
                    @if ($deadline = $transfer->ownerDefectReportDeadline())
                        {{-- C-38: arithmetic only. Nothing is triggered when it passes. --}}
                        <div class="flex justify-between py-2.5">
                            <dt class="text-gray-500">پایان مهلت اعلام ایراد توسط مالک</dt>
                            <dd class="text-gray-700 text-xs" dir="ltr">{{ $deadline->format('Y-m-d H:i') }}</dd>
                        </div>
                    @endif
                    @if ($transfer->notes)
                        <div class="py-2.5">
                            <dt class="text-gray-500 mb-1">یادداشت هنگام تحویل</dt>
                            <dd class="text-gray-700 text-xs leading-6 whitespace-pre-line">{{ $transfer->notes }}</dd>
                        </div>
                    @endif
                    <div class="flex justify-between py-2.5">
                        <dt class="text-gray-500">در اختیار</dt>
                        <dd class="text-gray-700 text-xs">{{ $operation->device?->currentCustody()->label() ?? '—' }}</dd>
                    </div>
                </dl>

                <p class="text-[11px] text-gray-400 mt-4 leading-6">
                    تحویل فیزیکی مالکیت دستگاه را تغییر نمی‌دهد. دستگاه همچنان متعلق به مالک ثبت‌شده است.
                    تأیید طرف مقابل صرفاً تأیید همین سابقه است و جایگزین امضا یا رسید قانونی نیست.
                </p>

                @if ($operation->device)
                    <a href="{{ route('admin.devices.custody', $operation->device) }}"
                       class="inline-block mt-3 text-xs text-brandBlue hover:underline">مشاهده سابقه کامل تحویل این دستگاه</a>
                @endif
            @else
                <p class="text-sm text-gray-400">هنوز درخواست تحویلی برای این عملیات ثبت نشده است.</p>
            @endif
        </div>

        {{-- Inspection evidence: staff-only, append-only, free text. No grade,
             severity or amount -- none is defined (C-39). --}}
        @if ($inspectable)
            <div class="bg-white rounded-xl border border-gray-200 p-5">
                <h3 class="text-sm font-black text-gray-800 mb-4">بازرسی دستگاه</h3>

                @if ($operation->inspections->isEmpty())
                    <p class="text-sm text-gray-400">بازرسی‌ای ثبت نشده است.</p>
                @else
                    <ol class="text-sm divide-y divide-gray-100">
                        @foreach ($operation->inspections->sortBy('id') as $inspection)
                            <li class="py-2.5">
                                <div class="flex items-start justify-between gap-3">
                                    <p class="text-[11px] text-gray-500">
                                        {{ $inspection->stage->label() }} · {{ $inspection->inspector?->full_name ?? '—' }}
                                    </p>
                                    <span class="text-[11px] text-gray-400 shrink-0" dir="ltr">{{ $inspection->inspected_at?->format('Y-m-d H:i') }}</span>
                                </div>
                                <p class="text-gray-700 text-xs leading-6 mt-1 whitespace-pre-line">{{ $inspection->findings }}</p>

                                {{-- C-39: the expert's amount. Recorded only; nobody is charged. --}}
                                @if ($inspection->stage === App\Enums\RentalInspectionStage::CustomerReturn)
                                    @foreach ($inspection->damageAssessments->sortBy('id') as $assessment)
                                        <div class="mt-2 bg-amber-50 border border-amber-100 rounded-lg px-3 py-2 text-[11px] text-amber-800">
                                            ارزیابی خسارت: {{ persian_number(number_format($assessment->amount)) }} تومان
                                            · {{ $assessment->assessor?->full_name ?? '—' }}
                                            · <span dir="ltr">{{ $assessment->assessed_at?->format('Y-m-d H:i') }}</span>
                                            @if ($assessment->evidence_reference)
                                                · مدرک: <span dir="ltr">{{ $assessment->evidence_reference }}</span>
                                            @endif
                                            @if ($assessment->notes)
                                                <p class="mt-1 whitespace-pre-line">{{ $assessment->notes }}</p>
                                            @endif
                                        </div>
                                    @endforeach

                                    @if ($canManage)
                                        <form method="POST" action="{{ route('admin.operations.inspections.damage.store', $inspection) }}"
                                              class="mt-2 grid grid-cols-1 sm:grid-cols-3 gap-2">
                                            @csrf
                                            <input type="number" name="amount" min="0" step="1" required dir="ltr" placeholder="مبلغ خسارت (تومان)"
                                                   class="border border-gray-200 rounded-lg px-3 py-2 text-xs focus:outline-none focus:border-brandBlue">
                                            <input type="text" name="evidence_reference" maxlength="255" placeholder="شناسه مدرک (اختیاری)"
                                                   class="border border-gray-200 rounded-lg px-3 py-2 text-xs focus:outline-none focus:border-brandBlue">
                                            <input type="text" name="notes" maxlength="2000" placeholder="توضیح کارشناس (اختیاری)"
                                                   class="border border-gray-200 rounded-lg px-3 py-2 text-xs focus:outline-none focus:border-brandBlue">
                                            <button type="submit" data-confirm="ارزیابی خسارت ثبت شود؟ سابقه ثبت‌شده قابل ویرایش نیست."
                                                    class="sm:col-span-3 border border-amber-200 text-amber-700 rounded-lg px-3 py-2 text-xs font-bold">ثبت ارزیابی خسارت</button>
                                        </form>
                                    @endif
                                @endif
                            </li>
                        @endforeach
                    </ol>
                @endif

                @if ($canManage && $transfer && $transfer->isPossessionMoved())
                    <form method="POST" action="{{ route('admin.operations.inspections.store', $operation) }}" class="space-y-3 mt-4">
                        @csrf
                        <textarea name="findings" rows="3" required maxlength="2000" placeholder="شرح وضعیت و یافته‌های بازرسی"
                                  class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue"></textarea>
                        <button type="submit" data-confirm="این بازرسی ثبت شود؟ سابقه ثبت‌شده قابل ویرایش یا حذف نیست."
                                class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm font-bold text-gray-700">ثبت بازرسی</button>
                    </form>
                    <p class="text-[11px] text-gray-400 mt-3 leading-6">
                        بازرسی فقط سابقه وضعیت دستگاه است. مبلغ خسارت، جریمه یا اثر مالی از آن محاسبه نمی‌شود.
                    </p>
                @elseif (! $transfer || ! $transfer->isPossessionMoved())
                    <p class="text-[11px] text-gray-400 mt-3 leading-6">پس از ثبت تحویل فیزیکی، امکان ثبت بازرسی فراهم می‌شود.</p>
                @endif
            </div>
        @endif

        {{-- Audit history for this task and its handover, read from the
             existing audit_events table. No second audit mechanism. --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <h3 class="text-sm font-black text-gray-800 mb-4">سابقه رویدادها</h3>

            @if ($auditEvents->isEmpty())
                <p class="text-sm text-gray-400">رویدادی ثبت نشده است.</p>
            @else
                <ol class="text-sm divide-y divide-gray-100">
                    @foreach ($auditEvents as $event)
                        <li class="py-2.5 flex items-start justify-between gap-3">
                            <div>
                                <p class="text-gray-700 text-xs font-mono" dir="ltr">{{ $event->action }}</p>
                                <p class="text-[11px] text-gray-400 mt-1">
                                    {{ $event->actor_label ?? 'سیستم' }}
                                    @if ($event->result !== 'success')
                                        <span class="text-red-600">· {{ $event->result }}</span>
                                    @endif
                                </p>
                            </div>
                            <span class="text-[11px] text-gray-400 shrink-0" dir="ltr">{{ $event->occurred_at?->format('Y-m-d H:i') }}</span>
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>
    </div>

    <div class="space-y-5">
        @if ($canManage && ! $operation->isTerminal())
            @if (! $operation->hasDevice())
                <div class="bg-white rounded-xl border border-gray-200 p-5">
                    <h3 class="text-sm font-black text-gray-800 mb-1">تخصیص دستگاه</h3>
                    <p class="text-[11px] text-gray-400 mb-4 leading-6">
                        انتخاب دستگاه به‌صورت خودکار انجام نمی‌شود. قاعده تخصیص هنوز تعیین نشده است و باید یک کارشناس دستگاه را انتخاب کند.
                    </p>

                    @if ($candidates->isEmpty())
                        <p class="text-sm text-gray-400">دستگاه تأییدشده‌ای برای این مدل موجود نیست.</p>
                    @else
                        <form method="POST" action="{{ route('admin.operations.device', $operation) }}" class="space-y-3">
                            @csrf
                            <select name="device_id" required
                                    class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue">
                                <option value="">انتخاب کنید…</option>
                                @foreach ($candidates as $candidate)
                                    <option value="{{ $candidate->id }}">
                                        {{ $candidate->maskedSerial() }} — {{ $candidate->ownerLabel() }}
                                    </option>
                                @endforeach
                            </select>
                            <button type="submit" class="w-full bg-brandBlue text-white rounded-xl px-4 py-2.5 text-sm font-bold">ثبت دستگاه</button>
                        </form>
                    @endif
                </div>
            @endif

            @if ($operation->state === RentalOperationState::Scheduled)
                <div class="bg-white rounded-xl border border-gray-200 p-5">
                    <h3 class="text-sm font-black text-gray-800 mb-4">زمان‌بندی</h3>
                    <form method="POST" action="{{ route('admin.operations.schedule', $operation) }}" class="space-y-3">
                        @csrf
                        <input type="datetime-local" name="scheduled_at" dir="ltr"
                               value="{{ $operation->scheduled_at?->format('Y-m-d\TH:i') }}"
                               class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue">
                        <button type="submit" class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm font-bold text-gray-700">ثبت زمان</button>
                    </form>
                </div>
            @endif

            @if (in_array($operation->state, [RentalOperationState::Scheduled, RentalOperationState::Failed], true))
                <form method="POST" action="{{ route('admin.operations.start', $operation) }}">
                    @csrf
                    <button type="submit" class="w-full bg-brandBlue text-white rounded-xl px-4 py-3 text-sm font-bold">{{ $startLabel }}</button>
                </form>
            @endif

            @if ($operation->state === RentalOperationState::InProgress)
                <div class="bg-white rounded-xl border border-gray-200 p-5">
                    <h3 class="text-sm font-black text-gray-800 mb-1">{{ $recordTitle }}</h3>
                    <p class="text-[11px] text-gray-400 mb-4 leading-6">{{ $recordHint }}</p>
                    <form method="POST" action="{{ route('admin.operations.custody', $operation) }}" class="space-y-3">
                        @csrf
                        <textarea name="notes" rows="2" placeholder="{{ $notesPlaceholder }}"
                                  class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue"></textarea>
                        <button type="submit" data-confirm="{{ $recordTitle }}؟"
                                class="w-full bg-green-600 text-white rounded-xl px-4 py-2.5 text-sm font-bold">ثبت تحویل</button>
                    </form>
                </div>

                <div class="bg-white rounded-xl border border-gray-200 p-5">
                    <h3 class="text-sm font-black text-gray-800 mb-4">ثبت ناموفق بودن</h3>
                    <form method="POST" action="{{ route('admin.operations.fail', $operation) }}" class="space-y-3">
                        @csrf
                        <input type="text" name="reason" required maxlength="500" placeholder="دلیل"
                               class="w-full border border-gray-200 rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:border-brandBlue">
                        <button type="submit" data-confirm="این عملیات ناموفق ثبت شود؟"
                                class="w-full border border-red-200 text-red-600 rounded-xl px-4 py-2.5 text-sm font-bold">ثبت ناموفق</button>
                    </form>
                    <p class="text-[11px] text-gray-400 mt-3 leading-6">
                        ثبت ناموفق بودن هیچ اقدام دیگری را فعال نمی‌کند: بازگشت وجه، جریمه مالک، جایگزینی دستگاه و لغو رزرو هنوز تعیین‌تکلیف نشده‌اند.
                    </p>
                </div>
            @endif
        @endif

        @if ($operation->state === RentalOperationState::NotRequired)
            <div class="bg-gray-50 border border-gray-200 rounded-xl p-5 text-xs text-gray-600 leading-6">
                دستگاه تخصیص‌یافته هم‌اکنون در اختیار گیم‌پک است (یا متعلق به خود گیم‌پک است)، بنابراین تحویل گرفتن از مالک موضوعیت ندارد و سابقه تحویلی ثبت نمی‌شود.
            </div>
        @endif
    </div>
</div>
@endsection
