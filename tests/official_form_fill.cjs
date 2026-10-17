// P5-M64c test: fills the official blank form with the map the page gives (tf_pdf_map), exactly as
// public/assets/official-form.js does, and prints what the filled PDF holds. Fails if a field the map
// names is not in the form (a new edition with other field names).
//   node official_form_fill.cjs <app public dir> <map.json> <out.pdf>
const fs = require('fs');
const path = require('path');
const [pubArg, mapFile, out] = process.argv.slice(2);
const pub = path.resolve(pubArg);
const L = require(pub + '/assets/pdf-lib.min.js');
(async () => {
  const map = JSON.parse(fs.readFileSync(mapFile, 'utf8'));
  const pdf = await L.PDFDocument.load(fs.readFileSync(pub + map.src));
  const form = pdf.getForm();
  form.acroForm.dict.delete(L.PDFName.of('XFA'));
  for (const k of Object.keys(map.text)) form.getTextField(k).setText(String(map.text[k]));
  for (const k of Object.keys(map.choices)) form.getDropdown(k).select(map.choices[k]);
  for (const k of map.checks) form.getCheckBox(k).check();
  const values = {};
  for (const k of Object.keys(map.text)) values[k] = form.getTextField(k).getText();
  const checked = map.checks.filter(k => form.getCheckBox(k).isChecked());
  const font = await pdf.embedFont(L.StandardFonts.Helvetica);
  const pages = pdf.getPages();
  if (map.draw_ticks) {
    for (const k of map.checks) for (const w of form.getCheckBox(k).acroField.getWidgets()) {
      const r = w.getRectangle(); const page = pages.find(pg => pg.ref === w.dict.get(L.PDFName.of('P'))) || pages[0];
      page.drawText('X', { x: r.x + r.width / 2 - 2.6, y: r.y + r.height / 2 - 3, size: 8, font });
    }
  }
  for (const im of (map.images || [])) {
    const png = await pdf.embedPng(Buffer.from(im.data.split(',')[1], 'base64'));
    pages[im.page].drawImage(png, { x: im.x, y: im.y, height: im.h, width: png.width * im.h / png.height });
  }
  for (const t of map.draw) pages[t.page].drawText(t.text, { x: t.x, y: t.y, size: 9, font });
  form.flatten();
  const bytes = await pdf.save();
  fs.writeFileSync(out, bytes);
  console.log(JSON.stringify({ values, checked, bytes: bytes.length, fields_left: pdf.getForm().getFields().length }));
})().catch(e => { console.error(String(e)); process.exit(1); });
