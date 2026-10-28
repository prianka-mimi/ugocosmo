<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center px-4 py-2 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-offset-2 transition ease-in-out duration-150']) }} style="background: #E87450; box-shadow: 0 4px 14px rgba(232,116,80,0.3);">
    {{ $slot }}
</button>
