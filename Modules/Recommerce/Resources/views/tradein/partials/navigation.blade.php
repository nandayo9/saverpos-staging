<nav class="sb-ti-nav" aria-label="Trade-In module">
    <a class="{{ $workspacePage === 'overview' ? 'active' : '' }}" href="{{ route('recommerce.tradeins.index') }}">Overview</a>
    <a class="{{ in_array($workspacePage, ['acquisitions','show'], true) ? 'active' : '' }}" href="{{ route('recommerce.tradeins.acquisitions') }}">Acquisitions</a>
    <a class="{{ $workspacePage === 'approvals' ? 'active' : '' }}" href="{{ route('recommerce.tradeins.approvals') }}">Approvals
@if($needsAttention['approvals'])<span class="badge">{{ $needsAttention['approvals'] }}</span>
@endif</a>
    <a class="{{ $workspacePage === 'reports' ? 'active' : '' }}" href="{{ route('recommerce.tradeins.reports') }}">Reports</a>
</nav>
