{{--
    Shared confirmation dialog for destructive admin actions.

    The Store used the browser's native confirm(), which cannot be styled, is
    not RTL-aware, and looks nothing like GamePek. Use adminConfirm() from
    admin/partials/scripts.blade.php instead of confirm().
--}}
<div id="admin-confirm-modal" class="hidden fixed inset-0 z-[90] items-center justify-center bg-black/50 px-4">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm overflow-hidden">
        <div class="p-5 text-right">
            <div class="flex items-start gap-3">
                <div id="admin-confirm-icon" class="w-10 h-10 rounded-full bg-red-50 text-red-500 flex items-center justify-center shrink-0">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                </div>
                <div class="flex-1">
                    <h3 id="admin-confirm-title" class="text-sm font-bold text-gray-800 mb-1">تأیید عملیات</h3>
                    <p id="admin-confirm-message" class="text-xs text-gray-600 leading-relaxed"></p>
                </div>
            </div>
        </div>
        <div class="flex gap-2 px-5 pb-5">
            <button type="button" id="admin-confirm-accept"
                    class="flex-1 bg-red-600 hover:bg-red-700 text-white text-sm font-bold py-2.5 rounded-xl transition-colors">
                تأیید
            </button>
            <button type="button" id="admin-confirm-cancel"
                    class="flex-1 border border-gray-200 text-gray-600 hover:bg-gray-50 text-sm font-bold py-2.5 rounded-xl transition-colors">
                انصراف
            </button>
        </div>
    </div>
</div>
