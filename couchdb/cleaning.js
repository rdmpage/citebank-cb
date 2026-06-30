{
  "_id": "_design/cleaning",
  "views": {
    "container-title": {
      "map": "  function isLetter(ch) {\n    return ch.toLowerCase() !== ch.toUpperCase();  // works for all bicameral scripts\n  }\n  \n  function(doc) {\n    if (doc['container-title']) {\n      var first = doc['container-title'].charAt(0);\n      if (!isLetter(first)) {\n         emit(first, doc['container-title']);\n      }\n    }\n  }"
    },
    "family-letter-first": {
      "map": "\n// very crude parsing of names\nfunction parse_author(author) {\n  var a = {};\n  \n  if (author.family) {\n    a.family = author.family;\n  }\n  if (author.given) {\n    a.given = author.given;\n  }\n  if (author.literal) {\n    a.literal = author.literal;\n  }\n\n  if (!a.family && a.literal) {\n    var m = a.literal.match(/(.*),\\s*(.*)/);\n    if (m) {\n      a.family = m[1];\n      a.given = m[2]\n    } else {\n      m = a.literal.match(/(.*)\\s+([^\\s]+)$/);\n      if (m) {\n       a.family = m[2];\n       a.given = m[1]\n     }\n    }\n  }\n  return a;\n}\n\nfunction isLetter(ch) {\n  return ch.toLowerCase() !== ch.toUpperCase();  // works for all bicameral scripts\n}\n\nfunction(doc) {\n  if (!doc.citebank || doc.citebank.type != 'work' || doc.citebank.deleted) {\n   return;\n  }\n\n  if (doc.author) {\n      if (doc.author[0]) {\n        var author = doc.author[0];\n         //emit([author, null], 1);\n        \n        author = parse_author(author);\n        \n        if (author.family) {\n          var first = author.family.charAt(0);\n          //if (!isLetter(first)) {\n            emit(first, author.family);\n          //}\n        }\n       \n      }\n  }\n}\n"
    }
  },
  "language": "javascript"
}