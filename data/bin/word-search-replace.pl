# https://github.com/jgm/pandoc/blob/master/src/Text/Pandoc/Writers/TEI.hs
# currently supports simple:strikethrough but not simple:underline
use strict;

use Win32::OLE;
use Win32::OLE::Const;

use File::Temp qw/ tempfile tempdir /;

use File::Spec;
use File::Spec::Functions qw( canonpath );

use constant {
    wdStory   => 6,
    wdReplaceAll  => 2,
};

my $num_args = $#ARGV + 1;
if ($num_args != 1) {
    print "\nUsage: word-search-replace.pl file.docx\n";
    exit;
}

my $fname = $ARGV[0];
my $fnameAbs = canonpath(File::Spec->rel2abs($fname));

my $word = Win32::OLE->new('Word.Application');

$word->{Visible} = 0;

my $document = $word->Documents->Open($fnameAbs);
$word->Selection->HomeKey(wdStory);
$word->Selection->Find->Font->{'Underline'} = 1;
$word->Selection->Find->Replacement->Font->{'Strikethrough'} = 1;
$word->Selection->Find->Execute({ Replace => wdReplaceAll });

my ($filehandle, $tmp) = tempfile(SUFFIX => '.docx');
close($filehandle);

$word->ActiveDocument->SaveAs($tmp);
$document->Close();
$word->Quit();

# now write to stdout
binmode STDOUT;
open FILE, $tmp;
binmode FILE;
while (<FILE>) {
        print STDOUT $_;
}
close FILE;

unlink($tmp);
