// P5-M67: a phone photo (often 3 to 6 MB) is made smaller in the phone before it is sent - at most 1600 pixels,
// JPEG - so it fits the server's upload limit and still reads. A PDF, or a photo already small, is sent as is.
(function () {
  if (!window.DataTransfer || !window.HTMLCanvasElement) { return; }
  var MAX = 1600, SMALL = 700 * 1024;
  document.querySelectorAll('input[type=file][data-shrink]').forEach(function (input) {
    input.addEventListener('change', function () {
      var f = input.files && input.files[0];
      if (!f || !/^image\/(jpeg|png|heic|heif|webp)$/i.test(f.type) || f.size <= SMALL) { return; }
      var img = new Image();
      var url = URL.createObjectURL(f);
      img.onload = function () {
        var scale = Math.min(1, MAX / Math.max(img.naturalWidth, img.naturalHeight));
        var c = document.createElement('canvas');
        c.width = Math.round(img.naturalWidth * scale); c.height = Math.round(img.naturalHeight * scale);
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
        URL.revokeObjectURL(url);
        c.toBlob(function (blob) {
          if (!blob || blob.size >= f.size) { return; }
          var dt = new DataTransfer();
          dt.items.add(new File([blob], f.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }));
          input.files = dt.files;
        }, 'image/jpeg', 0.82);
      };
      img.onerror = function () { URL.revokeObjectURL(url); };
      img.src = url;
    });
  });
})();
