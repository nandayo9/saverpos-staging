<details class="tw-dw-dropdown tw-dw-dropdown-end" style="margin: 10px;">
    <summary class="tw-bg-transparent tw-text-white tw-font-medium tw-text-sm md:tw-text-base select-none">
        {{ isset($_GET['lang']) ? config('constants.langs')[$_GET['lang']]['full_name'] : config('constants.langs')[config('app.locale')]['full_name'] }}
    </summary>
    {{-- This button is pinned to the physical top-right in every layout that uses it, so the
        menu must always anchor to the physical right and open leftward (inward). daisyUI's
        dropdown-end uses inset-inline-end, which rtl.css flips to left:0 and pushes the menu
        off-screen in Arabic. Force a physical right anchor so it stays on-screen in LTR and RTL. --}}
    <ul
        class="tw-p-2 tw-shadow tw-dw-menu tw-dw-dropdown-content tw-z-[1] tw-w-48 md:tw-w-56 tw-bg-white tw-rounded-xl tw-mt-3"
        style="left: auto; right: 0;">
        @foreach (config('constants.langs') as $key => $val)
            <li><a value="{{ $key }}" class="change_lang"> {{ $val['full_name'] }}</a>
            </li>
        @endforeach
    </ul>
</details>
