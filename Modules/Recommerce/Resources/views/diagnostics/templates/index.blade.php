@extends('layouts.app')

@section('title', 'Diagnostic templates')

@section('content')
<section class="container sb-record">
    <div class="record-header"><div><h1>Diagnostic templates</h1><p class="text-muted">Branch-scoped authoring. Published versions are immutable snapshots.</p></div><a class="btn btn-primary" href="{{ route('recommerce.diagnostic.templates.create') }}">Create template</a></div>
    @if(session('status'))<div class="alert alert-success" role="status">{{ session('status') }}</div>@endif
    @foreach($errors->all() as $error)<div class="alert alert-warning" role="alert">{{ $error }}</div>@endforeach
    @forelse($templates as $template)
        <div class="record-card"><h2>{{ $template->name }} <small>{{ $template->template_code }}</small></h2><div class="card-body"><p>Branch {{ $template->location_id ?: 'All branches (legacy)' }}</p>
            @foreach($template->versions as $version)<div class="checklist-item"><span>Version {{ $version->version_number }} · {{ $version->status }}</span><span><a class="btn btn-default btn-xs" href="{{ route('recommerce.diagnostic.templates.edit', [$template->id, $version->id]) }}">{{ $version->status === 'DRAFT' ? 'Edit draft' : 'View version' }}</a>@if($version->status === 'PUBLISHED') <form style="display:inline" method="POST" action="{{ route('recommerce.diagnostic.templates.revision', $template->id) }}">@csrf<button class="btn btn-default btn-xs" type="submit">New revision</button></form>@endif</span></div>@endforeach
        </div></div>
    @empty<p class="text-muted">No branch-scoped templates are available.</p>@endforelse
</section>
@endsection
