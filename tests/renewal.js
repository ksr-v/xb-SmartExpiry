const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

process.env.TZ = 'Asia/Shanghai';

const bundle = fs.readFileSync(path.join(__dirname, '../../../public/assets/admin/assets/index-CEIYH7i8.js'), 'utf8');
const editMatch = bundle.match(/h=e=>\{(const t=new Date,n=Number\(u\.getValues\("expired_at"\)\).+?)\};\/\*smart-expiry-edit-v3\*\//);
const createMatch = bundle.match(/h=e=>\{(const t=new Date,n=Number\(r\.getValues\("expired_at"\)\).+?)\};\/\*smart-expiry-create-v3\*\//);
assert.ok(editMatch, 'edit-user renewal function exists');
assert.ok(createMatch, 'create-user renewal function exists');

const RealDate = Date;
let now;
class FakeDate extends RealDate {
  constructor(...args) {
    if (args.length) super(...args);
    else super(now);
  }

  static now() {
    return now;
  }
}

function renew(functionBody, currentExpiry, months, nowIso, formName) {
  now = new RealDate(nowIso).getTime();
  let value = currentExpiry;
  const form = {
    getValues: key => (assert.equal(key, 'expired_at'), value),
    setValue: (key, next, options) => {
      assert.equal(key, 'expired_at');
      assert.deepEqual(options, { shouldDirty: true, shouldValidate: true });
      value = next;
    },
  };
  let closed = false;
  const close = state => { closed = !state; };
  const names = formName === 'u' ? ['u', 'o', 'Date'] : ['r', 'u', 'Date'];
  const handler = Function(...names, `return e=>{${functionBody}}`)(form, close, FakeDate);
  handler(months);
  assert.equal(closed, true, 'popover closes without submitting');
  return value;
}

function unix(iso) {
  return Math.floor(new RealDate(iso).getTime() / 1000);
}

function runSharedCases(functionBody, formName) {
  const baseNow = '2026-10-06T10:00:00+08:00';
  for (const [months, expected] of [
    [1, '2027-01-20T18:35:00+08:00'],
    [3, '2027-03-20T18:35:00+08:00'],
    [6, '2027-06-20T18:35:00+08:00'],
    [9, '2027-09-20T18:35:00+08:00'],
    [12, '2027-12-20T18:35:00+08:00'],
  ]) {
    assert.equal(renew(functionBody, unix('2026-12-20T18:35:00+08:00'), months, baseNow, formName), unix(expected));
  }

  for (const emptyOrPast of [null, undefined, '', 0, 'invalid', unix('2026-09-20T00:00:00+08:00')]) {
    assert.equal(renew(functionBody, emptyOrPast, 3, baseNow, formName), unix('2027-01-06T10:00:00+08:00'));
  }

  assert.equal(renew(functionBody, unix(baseNow), 1, baseNow, formName), unix('2026-11-06T10:00:00+08:00'));

  const afterSix = renew(functionBody, unix('2026-12-20T18:35:00+08:00'), 6, baseNow, formName);
  assert.equal(afterSix, unix('2027-06-20T18:35:00+08:00'));
  assert.equal(renew(functionBody, afterSix, 3, baseNow, formName), unix('2027-09-20T18:35:00+08:00'));

  const afterThree = renew(functionBody, null, 3, baseNow, formName);
  const afterAnotherThree = renew(functionBody, afterThree, 3, baseNow, formName);
  assert.equal(renew(functionBody, afterAnotherThree, 6, baseNow, formName), unix('2027-10-06T10:00:00+08:00'));

  assert.equal(
    renew(functionBody, unix('2027-01-31T21:35:00+08:00'), 1, baseNow, formName),
    unix('2027-02-28T21:35:00+08:00'),
  );
  assert.equal(
    renew(functionBody, unix('2028-01-31T21:35:00+08:00'), 1, baseNow, formName),
    unix('2028-02-29T21:35:00+08:00'),
  );
}

runSharedCases(editMatch[1], 'u');
runSharedCases(createMatch[1], 'r');

for (const key of ['expire_time_1month', 'expire_time_3months', 'expire_time_6months', 'expire_time_9months', 'expire_time_1year']) {
  assert.ok(bundle.includes(`edit.form.${key}`), `edit ${key} button exists`);
  assert.ok(bundle.includes(`generate.form.${key}`), `create ${key} button exists`);
}
assert.equal((bundle.match(/onClick:\(\)=>h\((1|3|6|9|12)\)/g) || []).length, 10, 'both scenes share one handler per form');
assert.ok(bundle.includes('type:"datetime-local",step:"1"'), 'date-time picker keeps second precision');
assert.ok(bundle.includes('smart-expiry-create-v3'), 'create-user bridge marker exists');
assert.ok(bundle.includes('r.setValue("expired_at",null,{shouldDirty:!0,shouldValidate:!0}),u(!1)'), 'permanent reuses null expiry semantics');
assert.ok((bundle.match(/className:"grid grid-cols-3 gap-2"/g) || []).length >= 2, 'expiry actions use a stable three-column mobile grid');
assert.equal((bundle.match(/className:"w-full min-w-0 px-1 text-xs sm:px-3 sm:text-sm"/g) || []).length, 12, 'all expiry buttons are mobile-safe');
for (const key of ['edit.form.expire_time_6months', 'edit.form.expire_time_9months', 'edit.form.expire_time_1year',
  'generate.form.expire_time_1month', 'generate.form.expire_time_3months', 'generate.form.expire_time_6months',
  'generate.form.expire_time_9months', 'generate.form.expire_time_1year']) {
  assert.ok(bundle.includes(`${key}",{defaultValue:`), `${key} has a translated fallback`);
}

const createStart = bundle.indexOf('Q.jsxs(P$t,{open:d,onOpenChange:u');
const createEnd = bundle.indexOf('Q.jsx(TYt,{control:r.control,name:"plan_id"', createStart);
const createExpiryUi = bundle.slice(createStart, createEnd);
assert.ok(createStart > 0 && createEnd > createStart, 'create-user expiry UI boundaries exist');
assert.equal(createExpiryUi.includes('cT('), false, 'expiry controls never call the create-user API');

console.log('SmartExpiry edit/create renewal tests passed');
