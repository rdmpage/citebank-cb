{
  "_id": "_design/container",
  "views": {
    "container-list": {
      "reduce": "_count",
      "map": "function(doc){\n  if(!doc.citebank || doc.citebank.type!=='container' || doc.junk){ return; }\n  var name = doc.name || '';\n  if(!name){ return; }\n  function fold(c){\n    c = c.toUpperCase();\n    var m = {'À':'A','Á':'A','Â':'A','Ã':'A','Ä':'A','Å':'A','Ā':'A','Ă':'A','Ą':'A','Æ':'A','Ç':'C','Ć':'C','Č':'C','Ĉ':'C','Ċ':'C','Ð':'D','Đ':'D','Ď':'D','È':'E','É':'E','Ê':'E','Ë':'E','Ē':'E','Ė':'E','Ę':'E','Ě':'E','Ì':'I','Í':'I','Î':'I','Ï':'I','Ī':'I','Į':'I','İ':'I','Ñ':'N','Ń':'N','Ň':'N','Ò':'O','Ó':'O','Ô':'O','Õ':'O','Ö':'O','Ø':'O','Ō':'O','Ő':'O','Ù':'U','Ú':'U','Û':'U','Ü':'U','Ū':'U','Ů':'U','Ű':'U','Ý':'Y','Ÿ':'Y','Ž':'Z','Ź':'Z','Ż':'Z','Š':'S','Ś':'S','Ş':'S','Ŝ':'S','Ł':'L','Ľ':'L','Ţ':'T','Ť':'T','Ŕ':'R','Ř':'R'};\n    return m[c] || c;\n  }\n  emit([fold(name.charAt(0)), name], doc.variants ? doc.variants.length : 0);\n}"
    },
    "container-junk": {
      "reduce": "_count",
      "map": "function(doc){\n  if(!doc.citebank || doc.citebank.type!=='container' || !doc.junk){ return; }\n  var name = doc.name || '';\n  emit([name.charAt(0).toUpperCase(), name], doc.variants ? doc.variants.length : 0);\n}"
    },
    "variant": {
      "map": "function(doc){\n  if(!doc.citebank || doc.citebank.type!=='container'){ return; }\n  if(doc.variants){ for(var i=0;i<doc.variants.length;i++){ emit(doc.variants[i], doc._id); } }\n}"
    },
    "manage": {
      "map": "function(doc){\n  if(!doc.citebank || doc.citebank.type!=='container' || doc.curated){ return; }\n  emit(doc.cluster_run, doc._id);\n}"
    }
  },
  "language": "javascript"
}
