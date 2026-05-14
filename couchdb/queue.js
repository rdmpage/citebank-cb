{
  "_id": "_design/queue",
  "_rev": "1-7ec48817712ca25e6d0c65da0866bb49",
  "views": {
    "visited": {
      "map": "function(doc) {\n  if (doc.citebank && doc.citebank.type == 'work' && !doc.citebank.deleted) {\n    var visited = (doc.citebank.clustering && doc.citebank.clustering.visited)\n      ? doc.citebank.clustering.visited\n      : null;\n    emit(visited, null);\n  }\n}"
    }
  },
  "language": "javascript"
}