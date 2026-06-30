{
  "_id": "_design/queue",
  "language": "javascript",
  "views": {
    "visited": {
      "map": "function(doc) {\n  if (doc.citebank && doc.citebank.type == 'work' && !doc.citebank.deleted) {\n    var visited = (doc.citebank.clustering && doc.citebank.clustering.visited)\n      ? doc.citebank.clustering.visited\n      : null;\n    emit(visited, null);\n  }\n}"
    },
    "by-container": {
      "map": "function(doc) {\n  if (!doc.citebank || doc.citebank.type !== 'work' || doc.citebank.deleted) return;\n\n  var name = doc['container-title'];\n  if (Array.isArray(name)) name = name[0];\n  if (!name) return;\n\n  var visited = (doc.citebank.clustering && doc.citebank.clustering.visited) || null;\n  emit([name, visited], null);\n}"
    },
    "by-author-family": {
      "map": "function parse_author(author) {\n  var a = {};\n  if (author.family) a.family = author.family;\n  if (author.given)  a.given  = author.given;\n  if (author.literal) a.literal = author.literal;\n\n  if (!a.family && a.literal) {\n    var m = a.literal.match(/(.*),\\s*(.*)/);\n    if (m) {\n      a.family = m[1];\n      a.given  = m[2];\n    } else {\n      m = a.literal.match(/(.*)\\s+([^\\s]+)$/);\n      if (m) {\n        a.family = m[2];\n        a.given  = m[1];\n      }\n    }\n  }\n  return a;\n}\n\nfunction(doc) {\n  if (!doc.citebank || doc.citebank.type !== 'work' || doc.citebank.deleted) return;\n  if (!doc.author || !doc.author[0]) return;\n\n  var a = parse_author(doc.author[0]);\n  if (!a.family) return;\n\n  var visited = (doc.citebank.clustering && doc.citebank.clustering.visited) || null;\n  emit([a.family, visited], null);\n}"
    }
  }
}