class JsonPathResolver {
  static dynamic resolve(dynamic data, String path) {
    if (data == null || path.isEmpty) return null;

    final segments = path.split('.');
    dynamic current = data;

    for (final segment in segments) {
      if (current == null) return null;

      if (current is Map) {
        if (!current.containsKey(segment)) return null;
        current = current[segment];
      } else if (current is List) {
        final index = int.tryParse(segment);
        if (index == null || index < 0 || index >= current.length) return null;
        current = current[index];
      } else {
        return null;
      }
    }

    return current;
  }
}
