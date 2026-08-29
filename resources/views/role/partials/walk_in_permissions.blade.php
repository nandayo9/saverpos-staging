<hr>
<div class="row check_group">
  <div class="col-md-3"><h4>Walk-In Intelligence</h4></div>
  <div class="col-md-9">
    @foreach([
      'walkin.create' => 'Record walk-ins',
      'walkin.close' => 'Close walk-ins as no sale',
      'walkin.assign' => 'Attribute POS sales to walk-ins',
      'walkin.view' => 'View permitted-branch analytics',
      'walkin.view_all' => 'View all-branch analytics',
    ] as $permission => $label)
      <div class="col-md-12"><div class="checkbox"><label>
        {!! Form::checkbox('permissions[]', $permission, in_array($permission, $role_permissions ?? []), ['class' => 'input-icheck']) !!} {{ $label }}
      </label></div></div>
    @endforeach
  </div>
</div>
