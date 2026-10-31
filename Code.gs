// Run setup once, then deploy as a Web app (execute as Me; access Anyone).
function setup() {
  const p = PropertiesService.getScriptProperties();
  if (!p.getProperty('SHEET_ID')) {
    const ss = SpreadsheetApp.create('ALPHA Attendance Backup');
    const sheet = ss.getSheets()[0]; sheet.setName('Attendance');
    sheet.appendRow(['Sync ID','Employee Name','Employee ID','Date','Time (PKT)','Location','Source timestamp']);
    sheet.setFrozenRows(1);
    sheet.getRange(1,1,1,7).setBackground('#12354a').setFontColor('#ffffff').setFontWeight('bold');
    sheet.setColumnWidth(2,240); sheet.setColumnWidth(5,220); sheet.setColumnWidth(6,220);
    sheet.hideColumns(1); sheet.hideColumns(7);
    p.setProperty('SHEET_ID',ss.getId());
  }
  if (!p.getProperty('SYNC_TOKEN')) p.setProperty('SYNC_TOKEN',Utilities.getUuid()+Utilities.getUuid());
  console.log('Sheet: https://docs.google.com/spreadsheets/d/'+p.getProperty('SHEET_ID'));
  console.log('Copy SYNC_TOKEN from Project Settings > Script properties into PHP config.');
}
function doPost(e) {
  const output = x => ContentService.createTextOutput(JSON.stringify(x)).setMimeType(ContentService.MimeType.JSON);
  let lock;
  try {
    const body = JSON.parse(e.postData.contents);
    const p = PropertiesService.getScriptProperties();
    if (!p.getProperty('SYNC_TOKEN') || body.token !== p.getProperty('SYNC_TOKEN')) return output({success:false,error:'Unauthorized'});
    if (!Array.isArray(body.rows) || body.rows.length > 100) throw Error('Invalid batch');
    const deletes = body.deletes || [];
    if (!Array.isArray(deletes) || deletes.length > 100 || deletes.some(id => !/^[a-f0-9]{64}-[1-9][0-9]*$/.test(id))) throw Error('Invalid deletions');
    for (const item of body.rows) {
      if (!/^[a-f0-9]{64}-[1-9][0-9]*$/.test(item.uid) || !item.record) throw Error('Invalid row');
      for (const key of ['name','id','date','time','location']) if (typeof item.record[key] !== 'string') throw Error('Invalid field');
    }
    lock = LockService.getScriptLock(); lock.waitLock(25000);
    const ss = SpreadsheetApp.openById(p.getProperty('SHEET_ID'));
    const sheet = ss.getSheetByName('Attendance');
    if (!sheet) throw Error('Attendance tab missing');
    // Retain IDs only, so delayed requests and historical re-import cannot restore deleted records.
    let log = ss.getSheetByName('_DeletedAttendanceIDs');
    if (!log) { log = ss.insertSheet('_DeletedAttendanceIDs'); log.appendRow(['Deleted Sync ID']); log.hideSheet(); }
    const logLast = log.getLastRow();
    const tombstones = new Set(logLast > 1 ? log.getRange(2,1,logLast-1,1).getValues().flat() : []);
    const newDeletes = [...new Set(deletes)].filter(id => !tombstones.has(id));
    if (newDeletes.length) {
      log.getRange(logLast+1,1,newDeletes.length,1).setNumberFormat('@').setValues(newDeletes.map(id=>[id]));
      SpreadsheetApp.flush();
      newDeletes.forEach(id=>tombstones.add(id));
    }
    // Delete bottom-up so removing one row does not shift another target.
    const previousLast = sheet.getLastRow();
    const previousIDs = previousLast > 1 ? sheet.getRange(2,1,previousLast-1,1).getValues().flat() : [];
    for (let i=previousIDs.length-1; i>=0; i--) if (tombstones.has(previousIDs[i])) sheet.deleteRow(i+2);
    const last = sheet.getLastRow();
    const seen = new Set(last > 1 ? sheet.getRange(2,1,last-1,1).getValues().flat() : []);
    const added = []; const ack = [];
    // Force literal text; names/IDs cannot become formulas, leading zeroes preserved.
    const literal = value => "'" + String(value == null ? '' : value);
    for (const item of body.rows) {
      if (!tombstones.has(item.uid) && !seen.has(item.uid)) {
        const r = item.record;
        added.push([item.uid,r.name,r.id,r.date,r.time,r.location,r.timestamp == null ? '' : r.timestamp].map(literal));
        seen.add(item.uid);
      }
      ack.push(item.uid);
    }
    if (added.length) sheet.getRange(last+1,1,added.length,7).setNumberFormat('@').setValues(added);
    SpreadsheetApp.flush();
    return output({success:true,ack,deletedAck:deletes});
  } catch (error) { return output({success:false,error:'Sync failed; retry safely'}); }
  finally { if (lock) lock.releaseLock(); }
}

// USER-SPECIFIC IMPORT: run this instead of setup(). Safe to repeat.
function connectExistingAndImport() {
  const id = '1bk2h_-3VI4rrFbGCYZ5SD9OUOb1HMFfOGeLEkvg-z58';
  const ss = SpreadsheetApp.openById(id);
  let sheet = ss.getSheetByName('Attendance');
  if (!sheet) sheet = ss.insertSheet('Attendance');
  const headers = ['Sync ID','Employee Name','Employee ID','Date','Time (PKT)','Location','Source timestamp'];
  if (sheet.getLastRow() === 0) sheet.appendRow(headers);
  else if (JSON.stringify(sheet.getRange(1,1,1,7).getValues()[0]) !== JSON.stringify(headers))
    throw Error('Existing Attendance headers differ. Stop and review before importing.');
  sheet.setFrozenRows(1);
  sheet.getRange(1,1,1,7).setBackground('#12354a').setFontColor('#ffffff').setFontWeight('bold');
  sheet.setColumnWidth(2,240); sheet.setColumnWidth(5,220); sheet.setColumnWidth(6,220);
  sheet.hideColumns(1); sheet.hideColumns(7);
  const props = PropertiesService.getScriptProperties();
  props.setProperty('SHEET_ID',id);
  if (!props.getProperty('SYNC_TOKEN')) props.setProperty('SYNC_TOKEN',Utilities.getUuid()+Utilities.getUuid());
  const rows = [{"uid": "57b38445859fa93effc3d90ba4b4b0c4000cda8864f0c0e7bc98d18fe532e28c-1", "record": {"name": "Muhammad Faizan Ahmed Khan", "id": "01", "date": "2026-10-23", "time": "10/23/2026, 09:23:19 AM", "location": "24.845396, 67.101852"}}, {"uid": "c72a858d28cf225be993d24d78a507f61fb042d0edd0d9d449c8e63a0ca5cec0-1", "record": {"name": "Ali Sattar", "id": "02", "date": "2026-10-23", "time": "10/23/2026, 09:13:02 AM", "location": "24.845410, 67.101806"}}, {"uid": "07a3bc849f1885cfbf115704eda7a0e21ec9861c09cd549bc095b5087d839b44-1", "record": {"name": "Ali Sattar", "id": "02", "date": "2026-10-23", "time": "10/23/2026, 09:13:00 AM", "location": "24.845410, 67.101806"}}, {"uid": "d3c6a5c68787255652f990aa72e0e1c4cd04918cf06b8c1a749633ffc3c716da-1", "record": {"name": "Ali Sattar", "id": "02", "date": "2026-10-22", "time": "10/22/2026, 06:24:49 PM", "location": "24.845605, 67.101860"}}, {"uid": "2ce614795918ad2e9af55b6e3a1c6433f569f2959955f2e4e19b6454cda1ac32-1", "record": {"name": "Muhammad Faizan Ahmed Khan", "id": "01", "date": "2026-10-22", "time": "10/22/2026, 06:24:48 PM", "location": "24.845447, 67.101795"}}, {"uid": "6a6d299bbc4df7070abda57d1a91b39636410731c5932557a6e0c11b84195ddd-1", "record": {"name": "wasi hasnain", "id": "03", "date": "2026-10-22", "time": "10/22/2026, 09:16:57 AM", "location": "24.862194, 67.090798"}}, {"uid": "b1911be0373b02d1fcf1fda9963a4f3b7a224a7e556bbfc83f91783c6ab7c159-1", "record": {"name": "wasi hasnain", "id": "03", "date": "2026-10-22", "time": "10/22/2026, 09:16:31 AM", "location": "24.860822, 67.089095"}}, {"uid": "f3b6ea7562eedbf9cd71929f107e7efee832991d442db95540ebd1f584c0720d-1", "record": {"name": "Muhammad Faizan Ahmed Khan", "id": "01", "date": "2026-10-22", "time": "10/22/2026, 09:04:38 AM", "location": "24.845432, 67.101804"}}, {"uid": "b915780a87edea972f7c8a0aee72a39774f80448746ca142634dbf9763faae3e-1", "record": {"name": "Ali Sattar", "id": "02", "date": "2026-10-22", "time": "10/22/2026, 08:58:47 AM", "location": "24.845433, 67.101793"}}, {"uid": "1f52c013ac5b03d1822f5cc0c7a2a22dbc1f08da6809681631b97f5599443f2e-1", "record": {"name": "Ali Sattar", "id": "02", "date": "2026-10-21", "time": "10/21/2026, 07:14:22 PM", "location": "24.845530, 67.101864"}}, {"uid": "f2cc6071a69344de4db5feb3243e4c0b9e7297e025952146d9f31ee460ef65d4-1", "record": {"name": "Muhammad Faizan Ahmed Khan", "id": "01", "date": "2026-10-21", "time": "10/21/2026, 07:13:05 PM", "location": "24.845469, 67.101778"}}, {"uid": "4567bdb4593cebd9ce98d0307243b8018b00cd020f7513ea50aafbc5c6b946b0-1", "record": {"name": "wasi hasnain", "id": "03", "date": "2026-10-21", "time": "10/21/2026, 09:40:24 AM", "location": "24.884285, 67.168842"}}, {"uid": "f8649aac660ad57448225c45afa4a2d7a586053dc7b4efee386d913310a7f80b-1", "record": {"name": "Muhammad Faizan Ahmed Khan", "id": "01", "date": "2026-10-21", "time": "10/21/2026, 09:27:16 AM", "location": "24.845565, 67.101827"}}, {"uid": "ce78622326c5187efca8f2e923dd07041521a5fcd05ac47a162e2c8dfb3156ff-1", "record": {"name": "Ali Sattar", "id": "02", "date": "2026-10-21", "time": "10/21/2026, 08:58:27 AM", "location": "24.845434, 67.101792"}}, {"uid": "513c751aa82431189e99d19edea6a771aefaf0aa744e81247153cb256fbcfbbd-1", "record": {"name": "Ali Sattar", "id": "02", "date": "2026-10-21", "time": "10/21/2026, 08:58:10 AM", "location": "24.845427, 67.101792"}}, {"uid": "feb313accaf305b120d9541c4454997888bd069e52fc7076e62f76282ab5e694-1", "record": {"name": "Imransadiq", "id": "Super", "date": "2026-10-20", "time": "10/20/2026, 10:54:05 AM", "location": "24.878309, 67.176397"}}, {"uid": "a4f91ebaf168d7a99cebdcd156ae155373f8e492b41f7d78f2d7889477b2b074-1", "record": {"name": "Muhammad Faizan Ahmed Khan", "id": "01", "date": "2026-10-20", "time": "10/20/2026, 09:28:45 AM", "location": "24.845445, 67.101776"}}, {"uid": "dd880c184a50594e511289e676b017693ce78912960260699b81c3a27be08126-1", "record": {"name": "Muhammad Faizan Ahmed Khan", "id": "01", "date": "2026-10-20", "time": "10/20/2026, 09:28:43 AM", "location": "24.845445, 67.101776"}}, {"uid": "9f29c91498d711a8bbba36f5fc54c7a8e84963ac0dab7f837f407b6d960cb895-1", "record": {"name": "Muhammad Faizan Ahmed Khan", "id": "01", "date": "2026-10-20", "time": "10/20/2026, 09:28:41 AM", "location": "24.845445, 67.101776"}}, {"uid": "2e73411c252798520a551b6beee0703a9947b5230dc929453bdcadfd885038d4-1", "record": {"name": "Muhammad Faizan Ahmed Khan", "id": "01", "date": "2026-10-20", "time": "10/20/2026, 09:28:39 AM", "location": "24.845445, 67.101776"}}, {"uid": "8450d8dc18e25fd71c82c45169018fbbea10d244f85a01c8f697056ec6163fb0-1", "record": {"name": "wasi hasnain", "id": "03", "date": "2026-10-20", "time": "10/20/2026, 09:25:40 AM", "location": "24.884272, 67.168892"}}];
  const result = JSON.parse(doPost({postData:{contents:JSON.stringify({token:props.getProperty('SYNC_TOKEN'),rows:rows})}}).getContent());
  if (!result.success || result.ack.length !== 21) throw Error('Import incomplete; check permissions and retry.');
  console.log('21 historical records acknowledged. Existing matching Sync IDs were not duplicated.');
  console.log('Sheet: '+ss.getUrl());
}
