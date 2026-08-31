{{--
    Data-driven filter facets.

    The Store hardcoded three PlayStation facets (ظرفیت، ریجن، نوع محصول) as
    literal Blade arrays, duplicated once for desktop and once for the mobile
    sheet — six blocks to keep in sync for every filter change.

    This renders whatever CatalogService::availableFacets() declares, from
    config('rental.catalog.facets') and each category's own `filters` column.
    Adding a rental facet is a config or admin-panel change, not a view edit.

    Params:
      $facets         — ['key' => ['label' => '…', 'options' => ['value' => 'label']]]
      $selectedFacets — ['key' => 'value']
      $buildUrl       — closure(array $overrides): string
      $idPrefix       — '' for desktop, 'm-' for the mobile sheet (ids must be unique)
--}}
@php
    $idPrefix = $idPrefix ?? '';
@endphp
@foreach($facets as $facetKey => $facet)
    @php
        $options = $facet['options'] ?? [];
        $panelId = $idPrefix . 'filter-' . $facetKey;
        $current = (string) ($selectedFacets[$facetKey] ?? '');
    @endphp
    @continue(empty($options))

    <div class="py-2 border-b border-gray-100">
        <button onclick="toggleAccordion('{{ $panelId }}')" class="flex items-center justify-between w-full py-2 text-gray-800 hover:text-brandBlue transition-colors group">
            <span class="text-sm font-bold">{{ $facet['label'] ?? $facetKey }}</span>
            <i class="fa-solid fa-chevron-down text-xs text-gray-400 accordion-icon group-hover:text-brandBlue" id="icon-{{ $panelId }}"></i>
        </button>
        <div id="{{ $panelId }}" class="accordion-content collapsed">
            <div class="flex flex-col gap-3 pt-2 pb-2">
                @foreach($options as $value => $label)
                    @php
                        $isActive = $current === (string) $value;
                    @endphp
                    <a href="{{ $buildUrl([$facetKey => $isActive ? null : $value]) }}" class="flex items-center gap-3 cursor-pointer group">
                        <span class="w-5 h-5 border-2 rounded flex items-center justify-center transition-colors {{ $isActive ? 'filter-check-active' : 'border-gray-300 group-hover:border-brandBlue' }}">
                            <i class="fa-solid fa-check text-white text-[10px] {{ $isActive ? '' : 'opacity-0' }}"></i>
                        </span>
                        <span class="text-sm {{ $isActive ? 'text-brandBlue font-bold' : 'text-gray-600' }}">{{ $label }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    </div>
@endforeach
