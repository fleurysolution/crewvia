// P5-M64c: the official W-4 or I-9, as published (public/assets/forms), filled in the browser from the signed
// record (the map in #tf-pdf-map, built by tf_pdf_map), flattened so nothing can be changed, and saved.
// pdf-lib 1.17.1 (MIT, public/assets/pdf-lib.min.js). The filled form is never sent anywhere.
(function () {
  var button = document.querySelector('[data-official-pdf]');
  var status = document.getElementById('tf-pdf-status');
  if (!button || !window.PDFLib) { return; }
  var L = window.PDFLib;
  button.addEventListener('click', async function () {
    var map = JSON.parse(document.getElementById(button.dataset.officialPdf).textContent);
    var before = status.textContent;
    status.textContent = status.dataset.wait;
    button.disabled = true;
    try {
      var bytes = await (await fetch(map.src, { credentials: 'same-origin' })).arrayBuffer();
      var pdf = await L.PDFDocument.load(bytes);
      var form = pdf.getForm();
      // The forms also carry an XFA copy, which pdf-lib cannot write: without it, readers use the fields.
      form.acroForm.dict.delete(L.PDFName.of('XFA'));
      Object.keys(map.text).forEach(function (k) { form.getTextField(k).setText(String(map.text[k])); });
      Object.keys(map.choices).forEach(function (k) {
        var dd = form.getDropdown(k);
        if (dd.getOptions().indexOf(map.choices[k]) !== -1) { dd.select(map.choices[k]); }
      });
      var font = await pdf.embedFont(L.StandardFonts.Helvetica);
      var pages = pdf.getPages();
      map.checks.forEach(function (k) {
        var box = form.getCheckBox(k);
        box.check();
        if (!map.draw_ticks) { return; }
        // The W-4's boxes have no visible "on" look of their own: an X is drawn in each one ticked.
        box.acroField.getWidgets().forEach(function (w) {
          var r = w.getRectangle();
          var ref = w.dict.get(L.PDFName.of('P'));
          var page = pages.filter(function (p) { return p.ref === ref; })[0] || pages[0];
          page.drawText('X', { x: r.x + r.width / 2 - 2.6, y: r.y + r.height / 2 - 3, size: 8, font: font });
        });
      });
      for (const im of (map.images || [])) {
        const png = await pdf.embedPng(Uint8Array.from(atob(im.data.split(',')[1]), function (c) { return c.charCodeAt(0); }));
        pages[im.page].drawImage(png, { x: im.x, y: im.y, height: im.h, width: png.width * im.h / png.height });
      }
      map.draw.forEach(function (t) { pages[t.page].drawText(t.text, { x: t.x, y: t.y, size: 9, font: font }); });
      form.flatten();
      var blob = new Blob([await pdf.save()], { type: 'application/pdf' });
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = map.file;
      document.body.appendChild(a);
      a.click();
      setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 30000);
      status.textContent = before;
    } catch (e) {
      status.textContent = status.dataset.fail;
      if (window.console) { console.error(e); }
    }
    button.disabled = false;
  });
})();
