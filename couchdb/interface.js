{
  "_id": "_design/interface",
  "views": {
    "container-letter": {
      "reduce": "_sum",
      "map": "function(doc) {\n  if (!doc.citebank || doc.citebank.type != 'work' || doc.citebank.deleted) {\n   return;\n  }\n   \n  if (doc['container-title']) {\n \n    // normalised key for sorting\n    var name = '';\n    if (Array.isArray(doc['container-title'])) {\n      name = doc['container-title'][0];\n    } else {\n      name = doc['container-title'];\n    }\n\n    // output first letter\n    emit ([name.charAt(0).toUpperCase(), name], 1);\n  }\n}"
    },
    "container-year-page": {
      "reduce": "_sum",
      "map": "function issued_to_year(doc) {\n     var year = null;\n     if (doc.issued) {\n        if (typeof doc.issued === 'object') {\n          year = parseInt(doc.issued['date-parts'][0][0]);\n        }\n        else {\n          year = parseInt(doc.issued);\n        }\n     }\n\n      return year;\n}\n\n\nfunction(doc) {\n  if (!doc.citebank || doc.citebank.type != 'work' || doc.citebank.deleted) {\n   return;\n  }\n   \n  if (doc['container-title']) {\n    var container = doc['container-title'];\n    if (Array.isArray(container)) {\n      container = doc['container-title'][0];\n    }\n    var key = [];\n       \n      key.push(container);\n\n      var year = issued_to_year(doc);\n   \n      if (year) {\n        key.push(year);\n      }\n\n      var spage = null;\n\n      if (doc['page-first']) {\n        spage = doc['page-first'];\n      }\n      else {\n        if (doc.page) {\n          spage = doc.page;\n          var delimiter = spage.indexOf('-');\n          if (delimiter != -1) {\n            spage = spage.substring(0, delimiter);\n          }\n        }\n      }\n\n      if (spage) {\n        if (spage.match(/^[0-9]+$/)) {\n           key.push(parseInt(spage));\n        } else {\n          key.push(spage);\n        }\n      }\n \n      if (key.length === 3) {\n        emit(key, 1);\n      \n\n    }\n  }\n}\n"
    },
    "doi": {
      "reduce": "_sum",
      "map": "function (doc) {\n  if (!doc.citebank || doc.citebank.type != 'work' || doc.citebank.deleted) {\n   return;\n  }\n   \n  if (doc.DOI) {\n    emit(doc.DOI.toLowerCase(), 1);\n  }\n}"
    },
    "cluster": {
      "map": "function (doc) {\n  if (!doc.citebank || doc.citebank.type != 'work' || doc.citebank.deleted) {\n   return;\n  }\n\n  if (doc.citebank.cluster) {\n      emit(doc.citebank.cluster, doc._id);\n  }\n}"
    },
    "is-referenced-by": {
      "map": "function (doc) {\n  if (!doc.citebank || doc.citebank.type != 'work' || doc.citebank.deleted) {\n   return;\n  }\n\n  if (doc['is-referenced-by']) {\n    for (var i in doc['is-referenced-by']) {\n      if (doc['is-referenced-by'][i].DOI) {\n        emit(doc.citebank.cluster, 'https://doi.org/' + doc['is-referenced-by'][i].DOI);\n      }\n    }\n  }\n}"
    },
    "family-letter": {
      "reduce": "_sum",
      "map": "\n// very crude parsing of names\nfunction parse_author(author) {\n  var a = {};\n  \n  if (author.family) {\n    a.family = author.family;\n  }\n  if (author.given) {\n    a.given = author.given;\n  }\n  if (author.literal) {\n    a.literal = author.literal;\n  }\n\n  if (!a.family && a.literal) {\n    var m = a.literal.match(/(.*),\\s*(.*)/);\n    if (m) {\n      a.family = m[1];\n      a.given = m[2]\n    } else {\n      m = a.literal.match(/(.*)\\s+([^\\s]+)$/);\n      if (m) {\n       a.family = m[2];\n       a.given = m[1]\n     }\n    }\n  }\n  return a;\n}\n\nfunction(doc) {\n  if (!doc.citebank || doc.citebank.type != 'work' || doc.citebank.deleted) {\n   return;\n  }\n\n  if (doc.author) {\n      if (doc.author[0]) {\n        var author = doc.author[0];\n         //emit([author, null], 1);\n        \n        author = parse_author(author);\n        \n        if (author.family) {\n          emit([author.family.charAt(0).toUpperCase(), author.family], 1);\n        }\n       \n      }\n  }\n}\n"
    },
    "family-year": {
      "reduce": "_sum",
      "map": "// very crude parsing of names\nfunction parse_author(author) {\n  var a = {};\n  \n  if (author.family) {\n    a.family = author.family;\n  }\n  if (author.given) {\n    a.given = author.given;\n  }\n  if (author.literal) {\n    a.literal = author.literal;\n  }\n\n  if (!a.family && a.literal) {\n    var m = a.literal.match(/(.*),\\s*(.*)/);\n    if (m) {\n      a.family = m[1];\n      a.given = m[2]\n    } else {\n      m = a.literal.match(/(.*)\\s+([^\\s]+)$/);\n      if (m) {\n       a.family = m[2];\n       a.given = m[1]\n     }\n    }\n  }\n  return a;\n}\n\nfunction issued_to_year(doc) {\n     var year = null;\n     if (doc.issued) {\n        if (typeof doc.issued === 'object') {\n          year = parseInt(doc.issued['date-parts'][0][0]);\n        }\n        else {\n          year = parseInt(doc.issued);\n        }\n     }\n     return year;\n}\n\nfunction(doc) {\n  if (!doc.citebank || doc.citebank.type != 'work' || doc.citebank.deleted) {\n   return;\n  }\n\n  var family = null;\n  var year = null;\n  if (doc.author) {\n    if (doc.author[0]) {\n      var author = doc.author[0];\n      author = parse_author(author);\n      \n      if (author.family) {\n        family = author.family;\n      }\n    }\n  }\n  \n  if (doc.issued) {\n    year = issued_to_year(doc);\n  }\n \n    \n  if (family && year ) {\n    emit([family, year], 1);\n   }\n}\n"
    },
    "container-letter-list": {
      "reduce": "_sum",
      "map": "function(doc) {\n   if (!doc.citebank || doc.citebank.type != 'work' || doc.citebank.deleted) {\n   return;\n  }\n   \n  if (doc['container-title']) { \n    // normalised key for sorting\n    var name = '';\n    if (Array.isArray(doc['container-title'])) {\n      name = doc['container-title'][0];\n    } else {\n      name = doc['container-title'];\n    }\n\n    // output first letter\n    emit (name.charAt(0).toUpperCase(), 1);\n  }\n}"
    },
    "family-letter-first": {
      "reduce": "_sum",
      "map": "\n// very crude parsing of names\nfunction parse_author(author) {\n  var a = {};\n  \n  if (author.family) {\n    a.family = author.family;\n  }\n  if (author.given) {\n    a.given = author.given;\n  }\n  if (author.literal) {\n    a.literal = author.literal;\n  }\n\n  if (!a.family && a.literal) {\n    var m = a.literal.match(/(.*),\\s*(.*)/);\n    if (m) {\n      a.family = m[1];\n      a.given = m[2]\n    } else {\n      m = a.literal.match(/(.*)\\s+([^\\s]+)$/);\n      if (m) {\n       a.family = m[2];\n       a.given = m[1]\n     }\n    }\n  }\n  return a;\n}\n\nfunction(doc) {\n  if (!doc.citebank || doc.citebank.type != 'work' || doc.citebank.deleted) {\n   return;\n  }\n\n  if (doc.author) {\n      if (doc.author[0]) {\n        var author = doc.author[0];\n         //emit([author, null], 1);\n        \n        author = parse_author(author);\n        \n        if (author.family) {\n          emit(author.family.charAt(0).toUpperCase(), 1);\n        }\n       \n      }\n  }\n}\n"
    },
    "years": {
      "reduce": "_sum",
      "map": "function (doc) {\n    if (!doc.citebank || doc.citebank.type != 'work' || doc.citebank.deleted) {\n      return;\n    }\n\n    if (doc.issued) {\n      var year = 0;\n      if (typeof doc.issued === 'object') {\n        year = parseInt(doc.issued['date-parts'][0][0]);\n      } else {\n        year = parseInt(doc.issued);\n      }\n      emit(year,1);\n    }\n   }"
    }
  },
  "language": "javascript"
}