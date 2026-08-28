<div id="deleteModal" class="fixed inset-0 z-[90] flex items-center justify-center hidden">
    <div class="fixed inset-0 bg-black/60 backdrop-blur-sm" onclick="closeDeleteModal()"></div>
    <div class="bg-white dark:bg-slate-800 rounded-xl p-6 w-full max-w-sm relative z-10 mx-4 shadow-2xl border border-gray-200 dark:border-slate-700">
        <div class="text-center">
            <div class="w-14 h-14 bg-red-100 dark:bg-red-500/15 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg class="w-7 h-7 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
                </svg>
            </div>
            <h3 class="text-lg font-bold text-gray-800 dark:text-slate-200 mb-2">Delete Confirmation</h3>
            <p class="text-sm text-gray-600 dark:text-slate-400 mb-6">
                Are you sure you want to delete "<span id="deleteItemName" class="font-semibold text-gray-800 dark:text-slate-200"></span>"? This action cannot be undone.
            </p>
            <div class="flex gap-3 justify-center">
                <button onclick="closeDeleteModal()" class="btn btn-secondary">Cancel</button>
                <button onclick="confirmDelete()" class="btn btn-danger">Delete</button>
            </div>
        </div>
    </div>
</div>