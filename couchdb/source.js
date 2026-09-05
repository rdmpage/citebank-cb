{
  "_id": "_design/source",
  "views": {
    "container-titles": {
      "reduce": "_count",
      "map": "function(doc) {\n  if (!doc.citebank || doc.citebank.type != 'work' || doc.citebank.deleted) {\n   return;\n  }\n\n  var name = doc['container-title'];\n  if (Array.isArray(name)) {\n    name = name[0];\n  }\n  if (!name) {\n    return;\n  }\n\n  // One row per ISSN, not just the first: a journal often carries both print\n  // and electronic ISSNs, and the clustering pipeline uses each of them as a\n  // must-link key. Records with no ISSN emit a single row with an empty second\n  // column, so a title can appear both with and without an ISSN.\n  var issn = doc.ISSN;\n\n  if (issn && !Array.isArray(issn)) {\n    issn = [issn];\n  }\n\n  if (issn && issn.length > 0) {\n    for (var i = 0; i < issn.length; i++) {\n      emit([name, issn[i] || ''], 1);\n    }\n  } else {\n    emit([name, ''], 1);\n  }\n}"
    }
  },
  "language": "javascript"
}
