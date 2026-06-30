{
  "_id": "_design/matching",
  "views": {
    "doi": {
      "reduce": "_count",
      "map": "function (doc) {\n  if (!doc.citebank || doc.citebank.type != 'work' || doc.citebank.deleted) {\n    return;\n  }\n  \n  if (doc.DOI) {\n    emit(doc.DOI.toLowerCase(), 1);\n  }\n}"
    },
    "hash": {
      "reduce": "_count",
      "map": "function (doc) {\n    if (!doc.citebank || doc.citebank.type != 'work' || doc.citebank.deleted) {\n      return;\n    }\n\n    var hash = [];\n  \n    if (doc.issued) {\n      if (typeof doc.issued === 'object') {\n        hash.push(parseInt(doc.issued['date-parts'][0][0]));\n      } else {\n        hash.push(parseInt(doc.issued));\n      }\n    }\n\n    if (doc.volume && parseInt(doc.volume)) {\n      hash.push(parseInt(doc.volume));\n    }\n  \n    if (doc['page-first'] && parseInt(doc['page-first'])) {\n      hash.push(parseInt(doc['page-first']));\n    } else if (doc.page) {\n      var m = String(doc.page).match(/(\\d+)[-|–|−](\\d+)/);\n      if (m && parseInt(m[1])) {\n        hash.push(parseInt(m[1]));\n      } else if (parseInt(doc.page)) {\n        hash.push(parseInt(doc.page));\n      }\n    }\n\n    if (hash.length == 3) {\n      emit(hash, 1);\n    }\n  }"
    }
  },
  "language": "javascript"
}