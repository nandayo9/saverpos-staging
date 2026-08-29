import fs from 'node:fs';
import path from 'node:path';

const checkout = path.resolve(process.argv[2] || '.');

function read(relativePath) {
    return fs.readFileSync(path.join(checkout, relativePath), 'utf8');
}

function assert(condition, message) {
    if (!condition) throw new Error(message);
}

const routes = read('routes/web.php');
const controller = read('app/Http/Controllers/WalkInController.php');
const dashboard = read('resources/views/walk_ins/index.blade.php');
const posControls = read('resources/views/sale_pos/partials/pos_form.blade.php');
const posCreate = read('resources/views/sale_pos/create.blade.php');
const layouts = [
    'resources/views/layouts/app.blade.php',
    'resources/views/layouts/auth.blade.php',
    'resources/views/layouts/auth2.blade.php',
    'resources/views/layouts/guest.blade.php',
    'resources/views/layouts/install.blade.php',
    'resources/views/layouts/restaurant.blade.php',
].map(read);

assert(routes.includes("->name('walk-ins.index')"), 'Walk-In dashboard route must remain registered');
assert(routes.includes("->name('walk-ins.open')"), 'open Walk-In route must remain registered');
assert(routes.includes("->name('walk-ins.store')"), 'capture Walk-In route must remain registered');
assert(routes.includes("->name('walk-ins.close')"), 'no-sale close route must remain registered');

layouts.forEach((layout, index) => {
    assert(layout.includes('width=device-width, initial-scale=1'), `layout ${index + 1} must be mobile responsive`);
    assert(!layout.includes('maximum-scale'), `layout ${index + 1} must not disable browser zoom`);
    assert(!layout.includes('user-scalable'), `layout ${index + 1} must not disable browser zoom`);
});

assert(dashboard.includes('for="walkin_location_id"'), 'dashboard branch selector must have a label');
assert(dashboard.includes('for="walkin_start"') && dashboard.includes('for="walkin_end"'), 'dashboard date inputs must have labels');
assert(dashboard.includes("@foreach($datePresets as $preset)"), 'dashboard must render the date-range presets');
['Today', 'Yesterday', 'Last 7 Days', 'This Month'].forEach((label) => {
    assert(controller.includes(`'${label}'`), `dashboard preset ${label} must remain available`);
});
assert(dashboard.includes('for="walkin_no_sale_reason_{{ $walkIn->id }}"'), 'no-sale reason selector must have a label');
assert(dashboard.includes('name="location_id" value="{{ $locationId }}"'), 'restricted branch selection must retain its filter value');

assert(posControls.includes('id="capture_walk_in"') && posControls.includes('WALK-IN'), 'POS must retain the one-tap Walk-In capture control');
assert(posControls.includes('for="walk_in_id"'), 'POS Walk-In attribution selector must have a label');
assert(posControls.includes("route('walk-ins.index')"), 'POS must retain a Walk-In dashboard link');
assert(posCreate.includes("route('walk-ins.store')"), 'POS capture control must post to the Walk-In endpoint');
assert(posCreate.includes("$button.prop('disabled', false)"), 'POS capture control must re-enable after each request');

console.log('walkin-static-check: passed (routes, mobile zoom, labels, POS controls)');
