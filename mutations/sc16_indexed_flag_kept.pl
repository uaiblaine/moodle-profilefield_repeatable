# normalise_column(): a stored "indexed" flag is kept on read and written back on save.
s~private const FLAGS = \['title', 'subline', 'required'\];~private const FLAGS = ['title', 'subline', 'required', 'indexed'];~s;
