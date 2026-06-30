{
  "_id": "_design/author",
  "views": {
    "family-given": {
      "reduce": "_sum",
      "map": "\n// very crude parsing of names\nfunction parse_author(author) {\n  var a = {};\n  \n  if (author.family) {\n    a.family = author.family;\n  }\n  if (author.given) {\n    a.given = author.given;\n  }\n  if (author.literal) {\n    a.literal = author.literal;\n  }\n\n  if (!a.family && a.literal) {\n    var m = a.literal.match(/(.*),\\s*(.*)/);\n    if (m) {\n      a.family = m[1];\n      a.given = m[2]\n    } else {\n      m = a.literal.match(/(.*)\\s+([^\\s]+)$/);\n      if (m) {\n       a.family = m[2];\n       a.given = m[1]\n     }\n    }\n  }\n  return a;\n}\n\nfunction(doc) {\n  if (!doc.citebank || doc.citebank.type != 'work' || doc.citebank.deleted) {\n   return;\n  }\n\n  if (doc.author) {\n      if (doc.author[0]) {\n        var author = doc.author[0];\n         //emit([author, null], 1);\n        \n        author = parse_author(author);\n        \n        if (author.family && author.given) {\n          emit([author.family, author.given], 1);\n        }\n       \n      }\n  }\n}\n"
    }
  },
  "language": "javascript"
}