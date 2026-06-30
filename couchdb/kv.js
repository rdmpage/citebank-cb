{
  "_id": "_design/kv",
  "views": {
    "doi": {
      "reduce": "_count",
      "map": "function (doc) {\n  if (!doc.citebank || doc.citebank.type != 'work' || doc.citebank.deleted) {\n   return;\n  }\n  \n  if (doc.DOI) {\n    emit(doc.DOI.toLowerCase(), 1);\n  }\n}"
    },
    "container-title": {
      "reduce": "_count",
      "map": "function (doc) {\n  if (!doc.citebank || doc.citebank.type != 'work' || doc.citebank.deleted) {\n   return;\n  }\n  \n  if (doc['container-title']) {\n    if (Array.isArray(doc['container-title'])) {\n      emit(doc['container-title'][0], 1);\n    } else {\n     emit(doc['container-title'], 1);\n    }\n  }\n}"
    },
    "container-title-short": {
      "reduce": "_count",
      "map": "function (doc) {\n  if (!doc.citebank || doc.citebank.type != 'work' || doc.citebank.deleted) {\n   return;\n  }\n  \n  var container_title = '';\n  var container_title_short = '';\n  \n  \n  if (doc['container-title']) {\n    if (Array.isArray(doc['container-title'])) {\n      container_title = doc['container-title'][0];\n    } else {\n     container_title = doc['container-title'];\n    }\n  }\n  \n  if (doc['container-title-short']) {\n    if (Array.isArray(doc['container-title-short'])) {\n      container_title_short = doc['container-title-short'][0];\n    } else {\n     container_title_short = doc['container-title-short'];\n    }\n  }  \n  \n  if (container_title != '' && container_title_short != '') {\n    emit([container_title, container_title_short], 1)\n  }\n}"
    },
    "title": {
      "reduce": "_sum",
      "map": "function (doc) {\n  if (!doc.citebank || doc.citebank.type != 'work' || doc.citebank.deleted) {\n   return;\n  }\n  \n  // title\n  if (doc.title) {\n    if (Array.isArray(doc['title'])) {\n      emit(doc['title'][0], 1);\n    } else {\n     emit(doc.title, 1);\n    }\n  }\n  \n  // short title\n  \n  // multilingual\n}"
    },
    "original-title": {
      "reduce": "_sum",
      "map": "function (doc) {\n  if (!doc.citebank || doc.citebank.type != 'work' || doc.citebank.deleted) {\n   return;\n  }\n  \n  if (doc['original-title']) {\n    if (Array.isArray(doc['original-title'])) {\n      if (doc['original-title'].length > 0 && doc['original-title'] != \"\") {\n        emit(doc['original-title'][0], 1);\n      }\n    } else {\n     emit(doc['original-title'], 1);\n    }\n  }\n \n}"
    }
  },
  "language": "javascript"
}